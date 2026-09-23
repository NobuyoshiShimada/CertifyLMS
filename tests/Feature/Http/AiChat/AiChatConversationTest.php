<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI 相談の会話(作成 / 再開 / 表示 / タイトル編集 / 削除)とアクセス制御を検証する機能テスト。
 */
class AiChatConversationTest extends TestCase
{
    use AiChatTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAiChat();
    }

    public function test_index_shows_empty_state_or_redirects_to_latest_conversation(): void
    {
        $this->actingAs($this->student)->get(route('ai-chat.index'))->assertOk()->assertViewIs('ai-chat.empty-state');

        AiChatConversation::factory()->forUser($this->student)->create(['last_message_at' => now()->subDay()]);
        $latest = AiChatConversation::factory()->forUser($this->student)->create(['last_message_at' => now()]);

        $this->actingAs($this->student)->get(route('ai-chat.index'))->assertRedirect(route('ai-chat.conversations.show', $latest));
    }

    public function test_widget_on_section_creates_then_resumes_same_conversation(): void
    {
        $section = $this->sectionFor($this->student);

        $first = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id])
            ->assertCreated();
        $second = $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id])
            ->assertOk();

        $this->assertSame($first->json('conversation.id'), $second->json('conversation.id'));
        $conversation = AiChatConversation::sole();
        $this->assertSame($section->id, $conversation->section_id);
        $this->assertNotNull($conversation->enrollment_id);
        $this->assertSame('新規相談', $conversation->title);
    }

    public function test_full_screen_always_creates_new_conversation_even_for_same_section(): void
    {
        $section = $this->sectionFor($this->student);
        $this->actingAs($this->student)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id]);

        $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'full-screen', 'section_id' => $section->id])
            ->assertCreated();

        $this->assertSame(2, AiChatConversation::query()->count());
    }

    public function test_widget_without_section_creates_general_conversation(): void
    {
        $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget'])
            ->assertCreated()
            ->assertJsonPath('conversation.section_id', null);
    }

    public function test_section_of_not_enrolled_or_failed_certification_is_rejected_with_403(): void
    {
        $other = User::factory()->student()->inProgress()->create();
        $notMine = $this->sectionFor($other);
        $failed = $this->sectionFor($this->student, 'failed', '日商簿記');

        foreach ([$notMine, $failed] as $section) {
            $this->actingAs($this->student)
                ->postJson(route('ai-chat.conversations.store'), ['source' => 'widget', 'section_id' => $section->id])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('ai_chat_conversations', 0);
    }

    public function test_full_screen_with_first_message_uses_its_head_as_title_and_redirects_with_flash(): void
    {
        $this->llm->title = '';
        $message = str_repeat('あ', 40);

        $response = $this->actingAs($this->student)
            ->post(route('ai-chat.conversations.store'), ['source' => 'full-screen', 'message' => $message]);

        $conversation = AiChatConversation::sole();
        $response->assertRedirect(route('ai-chat.conversations.show', $conversation))->assertSessionHas('success');
        $this->assertSame(str_repeat('あ', 30), $conversation->title);
        $this->assertSame(2, $conversation->messages()->count());
    }

    /**
     * @dataProvider invalidStoreProvider
     *
     * @param array<string, mixed> $input
     */
    public function test_store_validation_returns_422(array $input, string $field): void
    {
        $this->actingAs($this->student)
            ->postJson(route('ai-chat.conversations.store'), $input)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidStoreProvider(): array
    {
        return [
            '存在しない教材' => [['source' => 'widget', 'section_id' => 'not-exists'], 'section_id'],
            '初回メッセージ 2001 文字' => [['source' => 'full-screen', 'message' => str_repeat('あ', 2001)], 'message'],
            '不正な起動経路' => [['source' => 'mobile'], 'source'],
        ];
    }

    public function test_show_returns_html_and_json_with_messages_for_owner(): void
    {
        $conversation = AiChatConversation::factory()->forUser($this->student)->create(['title' => '過去問相談']);
        AiChatMessage::factory()->forConversation($conversation)->create(['content' => '質問です']);
        AiChatMessage::factory()->forConversation($conversation)->assistant()->create(['created_at' => now()->addSecond()]);

        $this->actingAs($this->student)
            ->get(route('ai-chat.conversations.show', $conversation))
            ->assertOk()
            ->assertViewIs('ai-chat.show')
            ->assertSee('過去問相談')
            ->assertSee('質問です');

        $this->actingAs($this->student)
            ->getJson(route('ai-chat.conversations.show', $conversation))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonMissingPath('messages.1.output_tokens');
    }

    public function test_title_can_be_updated_within_1_to_100_chars(): void
    {
        $conversation = AiChatConversation::factory()->forUser($this->student)->create();

        $this->actingAs($this->student)
            ->patch(route('ai-chat.conversations.update', $conversation), ['title' => str_repeat('あ', 100)])
            ->assertRedirect(route('ai-chat.conversations.show', $conversation));
        $this->assertSame(str_repeat('あ', 100), $conversation->fresh()->title);

        foreach (['', str_repeat('あ', 101)] as $invalid) {
            $this->actingAs($this->student)
                ->patchJson(route('ai-chat.conversations.update', $conversation), ['title' => $invalid])
                ->assertStatus(422);
        }
    }

    public function test_destroy_physically_deletes_conversation_and_messages(): void
    {
        $conversation = AiChatConversation::factory()->forUser($this->student)->create();
        AiChatMessage::factory()->forConversation($conversation)->count(2)->create();

        $this->actingAs($this->student)
            ->delete(route('ai-chat.conversations.destroy', $conversation))
            ->assertRedirect(route('ai-chat.index'));

        $this->assertDatabaseCount('ai_chat_conversations', 0);
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->actingAs($this->student)->get(route('ai-chat.conversations.show', $conversation))->assertNotFound();
    }

    public function test_other_students_conversation_is_forbidden(): void
    {
        $conversation = AiChatConversation::factory()->forUser(User::factory()->student()->inProgress()->create())->create();

        $this->actingAs($this->student);
        $this->get(route('ai-chat.conversations.show', $conversation))->assertForbidden();
        $this->patch(route('ai-chat.conversations.update', $conversation), ['title' => 'x'])->assertForbidden();
        $this->delete(route('ai-chat.conversations.destroy', $conversation))->assertForbidden();
        $this->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => 'x'])->assertForbidden();

        $this->assertDatabaseCount('ai_chat_conversations', 1);
        $this->assertSame([], $this->llm->calls);
    }

    /**
     * @dataProvider nonLearningUserProvider
     */
    public function test_non_learning_users_are_forbidden(string $role, string $status): void
    {
        $user = User::factory()->{$role}()->{$status}()->create();

        $this->actingAs($user)->get(route('ai-chat.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('ai-chat.conversations.store'), ['source' => 'widget'])->assertForbidden();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nonLearningUserProvider(): array
    {
        return [
            'コーチ' => ['coach', 'inProgress'],
            '管理者' => ['admin', 'inProgress'],
            '修了した受講生' => ['student', 'graduated'],
            '招待中の受講生' => ['student', 'invited'],
        ];
    }

    public function test_missing_conversation_returns_404(): void
    {
        $this->actingAs($this->student)
            ->getJson(route('ai-chat.conversations.show', ['conversation' => '01j00000000000000000000000']))
            ->assertNotFound();
    }
}
