<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Certification;
use App\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * AI 相談のメッセージ送受信(文脈の自動付与 / 会話履歴 / 失敗時の保存と 502 / 日次上限 / タイトル自動生成 /
 * API キー未設定)を検証する機能テスト。Gemini は偽の LLM クライアントに差し替える。
 */
class AiChatMessageTest extends TestCase
{
    use AiChatTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAiChat();
    }

    private function conversation(array $attributes = []): AiChatConversation
    {
        return AiChatConversation::factory()->forUser($this->student)->create($attributes);
    }

    private function send(AiChatConversation $conversation, string $content = '過去問はどう進めればいいですか？'): TestResponse
    {
        return $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => $content]);
    }

    public function test_send_saves_both_messages_with_observation_metadata(): void
    {
        $conversation = $this->conversation();

        $this->send($conversation)
            ->assertOk()
            ->assertJsonPath('user_message.role', 'user')
            ->assertJsonPath('user_message.content', '過去問はどう進めればいいですか？')
            ->assertJsonPath('assistant_message.role', 'assistant')
            ->assertJsonPath('assistant_message.status', 'completed')
            ->assertJsonPath('assistant_message.content', $this->llm->reply)
            ->assertJsonMissingPath('assistant_message.output_tokens');

        $assistant = AiChatMessage::query()->where('role', 'assistant')->sole();
        $this->assertSame('fake-model', $assistant->model);
        $this->assertSame(123, $assistant->input_tokens);
        $this->assertSame(45, $assistant->output_tokens);
        $this->assertNotNull($assistant->response_time_ms);
    }

    public function test_section_conversation_passes_headings_and_certification_but_not_body(): void
    {
        $section = $this->sectionFor($this->student);
        $conversationId = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id])
            ->json('conversation.id');

        $this->send(AiChatConversation::findOrFail($conversationId))->assertOk();

        $system = $this->llm->replyCalls()[0]['system'];
        $this->assertStringContainsString('受講中の資格: 基本情報技術者', $system);
        $this->assertStringContainsString('Part 2. テクノロジ系', $system);
        $this->assertStringContainsString('Chapter 3. ネットワーク', $system);
        $this->assertStringContainsString('Section 4. TCP/IP の基礎', $system);
        $this->assertStringNotContainsString('セクション本文の秘密の長文', $system);
    }

    public function test_general_conversation_passes_only_default_certification_name(): void
    {
        $certification = Certification::factory()->published()->create(['name' => '応用情報技術者']);
        $enrollment = Enrollment::factory()->for($this->student, 'user')->for($certification)->learning()->create();
        $this->student->update(['default_enrollment_id' => $enrollment->id]);

        $this->send($this->conversation())->assertOk();

        $system = $this->llm->replyCalls()[0]['system'];
        $this->assertStringContainsString('受講中の資格: 応用情報技術者', $system);
        $this->assertStringNotContainsString('閲覧中の教材', $system);
    }

    public function test_no_context_when_no_section_and_no_active_default_enrollment(): void
    {
        $certification = Certification::factory()->published()->create(['name' => '退会した資格']);
        $enrollment = Enrollment::factory()->for($this->student, 'user')->for($certification)->failed()->create();
        $this->student->update(['default_enrollment_id' => $enrollment->id]);

        $this->send($this->conversation())->assertOk();

        $system = $this->llm->replyCalls()[0]['system'];
        $this->assertStringContainsString('一般的な学習相談', $system);
        $this->assertStringNotContainsString('退会した資格', $system);
        $this->assertStringNotContainsString(EnrollmentStatus::Failed->value, $system);
    }

    public function test_history_is_limited_and_excludes_error_messages(): void
    {
        config(['ai-chat.history_limit' => 3]);
        $conversation = $this->conversation();
        $base = now()->subMinutes(10);
        AiChatMessage::factory()->forConversation($conversation)->create(['content' => '古い質問', 'created_at' => $base]);
        AiChatMessage::factory()->forConversation($conversation)->assistant()->create(['content' => '古い回答', 'created_at' => $base->copy()->addMinute()]);
        AiChatMessage::factory()->forConversation($conversation)->create(['content' => '失敗した質問', 'created_at' => $base->copy()->addMinutes(2)]);
        AiChatMessage::factory()->forConversation($conversation)->error()->create(['content' => 'エラー応答', 'created_at' => $base->copy()->addMinutes(3)]);

        $this->send($conversation, '最新の質問')->assertOk();

        $history = $this->llm->replyCalls()[0]['history'];
        $this->assertSame(['古い回答', '失敗した質問', '最新の質問'], array_column($history, 'content'));
        $this->assertSame(['assistant', 'user', 'user'], array_column($history, 'role'));
    }

    public function test_ai_failure_keeps_user_message_saves_error_and_returns_502(): void
    {
        $this->llm->failWithStatus = 503;
        $conversation = $this->conversation();

        $this->send($conversation)
            ->assertStatus(502)
            ->assertJsonPath('upstream_status', 503)
            ->assertJsonPath('message', 'AI が応答できませんでした。しばらく時間をおいて再試行してください。')
            ->assertJsonPath('user_message.content', '過去問はどう進めればいいですか？')
            ->assertJsonPath('assistant_message.status', 'error');

        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'content' => '過去問はどう進めればいいですか？']);
        $error = AiChatMessage::query()->where('role', 'assistant')->sole();
        $this->assertSame(AiChatMessageStatus::Error, $error->status);
        $this->assertStringContainsString('503', (string) $error->error_detail);

        // 同じ内容を送り直して再質問できる(エラー応答は履歴に含めない)
        $this->llm->failWithStatus = null;
        $this->send($conversation)->assertOk();
        $this->assertNotContains('', array_column($this->llm->replyCalls()[1]['history'], 'content'));
    }

    public function test_daily_limit_counts_failures_and_returns_429(): void
    {
        config(['ai-chat.daily_message_limit' => 2]);
        $conversation = $this->conversation();

        $this->llm->failWithStatus = 500;
        $this->send($conversation)->assertStatus(502);
        $this->llm->failWithStatus = null;
        $this->send($conversation)->assertOk();

        $this->send($conversation)
            ->assertStatus(429)
            ->assertJsonPath('message', '本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。');

        $this->assertSame(2, AiChatMessage::query()->where('role', 'user')->count());
    }

    public function test_yesterdays_messages_do_not_count_toward_daily_limit(): void
    {
        config(['ai-chat.daily_message_limit' => 1]);
        $conversation = $this->conversation();
        AiChatMessage::factory()->forConversation($conversation)->create(['created_at' => now()->subDay()]);

        $this->send($conversation)->assertOk();
    }

    public function test_first_reply_generates_title_and_later_replies_do_not(): void
    {
        $conversation = $this->conversation();

        $this->send($conversation)->assertOk()->assertJsonPath('conversation.title', '過去問の進め方');

        $this->llm->title = '別のタイトル';
        $this->send($conversation)->assertOk();
        $this->assertSame('過去問の進め方', $conversation->fresh()->title);
    }

    public function test_title_generation_failure_or_disabled_keeps_provisional_title(): void
    {
        $this->llm->failTitle = true;
        $first = $this->conversation();
        $this->send($first)->assertOk();
        $this->assertSame('新規相談', $first->fresh()->title);

        $this->llm->failTitle = false;
        config(['ai-chat.title_generation_enabled' => false]);
        $second = $this->conversation();
        $this->send($second)->assertOk();
        $this->assertSame('新規相談', $second->fresh()->title);
    }

    /**
     * @dataProvider invalidContentProvider
     */
    public function test_invalid_content_returns_422(string $content): void
    {
        $this->send($this->conversation(), $content)->assertStatus(422)->assertJsonValidationErrors('content');

        $this->assertSame([], $this->llm->calls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidContentProvider(): array
    {
        return ['空' => [''], '2001 文字' => [str_repeat('あ', 2001)]];
    }

    public function test_missing_api_key_returns_500_with_unavailable_message(): void
    {
        $this->llm->configured = false;

        $this->actingAs($this->student)
            ->getJson(route('ai-chat.index'))
            ->assertStatus(500)
            ->assertJsonPath('message', 'AI 相談機能は現在ご利用いただけません。');
        $this->send($this->conversation())->assertStatus(500);
    }
}
