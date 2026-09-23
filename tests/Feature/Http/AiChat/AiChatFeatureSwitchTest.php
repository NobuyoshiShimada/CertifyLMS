<?php

declare(strict_types=1);

namespace Tests\Feature\Http\AiChat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 機能 OFF スイッチ(AI_CHAT_ENABLED=false)で AI 相談ルートが登録されず(404)、サイドバー / ウィジェットも出ないこと、
 * ON なら学習中受講生にウィジェットが出ることを検証する。ルート登録時に設定を読むため、環境変数を変えてアプリを作り直す。
 */
class AiChatFeatureSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->setAiChatEnv(null);
        parent::tearDown();
    }

    private function setAiChatEnv(?string $value): void
    {
        if ($value === null) {
            putenv('AI_CHAT_ENABLED');
            unset($_ENV['AI_CHAT_ENABLED'], $_SERVER['AI_CHAT_ENABLED']);

            return;
        }

        putenv("AI_CHAT_ENABLED={$value}");
        $_ENV['AI_CHAT_ENABLED'] = $value;
        $_SERVER['AI_CHAT_ENABLED'] = $value;
    }

    public function test_feature_off_unregisters_routes_and_hides_ui(): void
    {
        $this->setAiChatEnv('false');
        $this->refreshApplication();
        $this->setUpTraits();

        $student = User::factory()->student()->inProgress()->create();

        $this->assertFalse(Route::has('ai-chat.index'));
        $this->actingAs($student)->get('/ai-chat')->assertNotFound();
        $this->actingAs($student)->postJson('/ai-chat/conversations', ['source' => 'widget'])->assertNotFound();

        $this->actingAs($student)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee('data-ai-chat-widget', false)
            ->assertDontSee('AI 相談');
    }

    public function test_feature_on_shows_widget_to_learning_student_only(): void
    {
        $this->setAiChatEnv('true');
        $this->refreshApplication();
        $this->setUpTraits();

        $student = User::factory()->student()->inProgress()->create();
        $coach = User::factory()->coach()->inProgress()->create();

        $this->assertTrue(Route::has('ai-chat.index'));
        $this->actingAs($student)->get(route('dashboard.index'))->assertOk()->assertSee('data-ai-chat-widget', false);
        $this->actingAs($coach)->get(route('dashboard.index'))->assertOk()->assertDontSee('data-ai-chat-widget', false);
    }
}
