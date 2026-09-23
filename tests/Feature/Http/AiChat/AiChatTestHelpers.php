<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use App\Services\AiChat\AiChatLlmClient;
use Tests\Fakes\FakeAiChatLlmClient;

/**
 * AI 相談の機能テストで共通に使う準備(偽の LLM / 受講生 / 教材ツリー)。
 */
trait AiChatTestHelpers
{
    protected FakeAiChatLlmClient $llm;

    protected User $student;

    protected function setUpAiChat(): void
    {
        config(['ai-chat.enabled' => true, 'ai-chat.daily_message_limit' => 50, 'ai-chat.history_limit' => 20, 'ai-chat.title_generation_enabled' => true]);
        $this->llm = new FakeAiChatLlmClient;
        $this->app->instance(AiChatLlmClient::class, $this->llm);
        $this->student = User::factory()->student()->inProgress()->create();
    }

    /**
     * 受講登録(指定状態)付きの教材ツリーを作り、Section を返す。
     */
    protected function sectionFor(User $student, string $enrollmentState = 'learning', string $certificationName = '基本情報技術者'): Section
    {
        $certification = Certification::factory()->published()->create(['name' => $certificationName]);
        Enrollment::factory()->for($student, 'user')->for($certification)->{$enrollmentState}()->create();
        $part = Part::factory()->for($certification)->create(['order' => 2, 'title' => 'テクノロジ系']);
        $chapter = Chapter::factory()->for($part)->create(['order' => 3, 'title' => 'ネットワーク']);

        return Section::factory()->for($chapter)->create([
            'order' => 4,
            'title' => 'TCP/IP の基礎',
            'body' => 'セクション本文の秘密の長文',
        ]);
    }
}
