<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification\Api;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sanctum SPA Cookie 認証の CSRF 二段防御を検証する。
 *
 * Laravel はテスト実行中 CSRF 検証を自動スキップするため、スキップしない検証ミドルウェアへ差し替えて確認する。
 * 同一オリジン(stateful ドメイン)からのリクエストとして Referer を付けて送る。
 */
class SanctumCsrfTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.middleware.verify_csrf_token' => EnforcedVerifyCsrfToken::class]);
    }

    private function notificationFor(User $user): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\ChatMessageReceivedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['notification_type' => 'chat_message_received', 'title' => 't', 'message' => 'm', 'url' => '/chat-rooms/1'],
        ]);
    }

    public function test_csrf_cookie_endpoint_sets_xsrf_token_cookie(): void
    {
        $this->get('/sanctum/csrf-cookie', ['Referer' => self::ORIGIN])
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_post_without_csrf_token_returns_419(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $notification = $this->notificationFor($student);

        $this->actingAs($student)
            ->postJson(route('api.v1.notifications.read', $notification), [], ['Referer' => self::ORIGIN])
            ->assertStatus(419);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_post_with_csrf_token_from_cookie_succeeds(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $notification = $this->notificationFor($student);

        // JS と同じく、/sanctum/csrf-cookie が返した(暗号化済みの)XSRF-TOKEN Cookie の値を X-XSRF-TOKEN ヘッダで送る
        $xsrfCookie = $this->actingAs($student)
            ->get('/sanctum/csrf-cookie', ['Referer' => self::ORIGIN])
            ->getCookie('XSRF-TOKEN', decrypt: false)
            ->getValue();

        $this->actingAs($student)
            ->postJson(route('api.v1.notifications.read', $notification), [], [
                'Referer' => self::ORIGIN,
                'X-XSRF-TOKEN' => $xsrfCookie,
            ])
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }
}

/**
 * テスト実行中でも CSRF 検証をスキップしない VerifyCsrfToken。
 */
class EnforcedVerifyCsrfToken extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
