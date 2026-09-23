<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Notification\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 通知 JSON API(/api/v1/notifications)の認証・認可・バリデーション・一覧 / 既読化を検証する機能テスト。
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));
        $this->student = User::factory()->student()->inProgress()->create();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function notify(User $user, bool $unread = true, array $data = [], ?Carbon $at = null): DatabaseNotification
    {
        $at ??= now();

        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\ChatMessageReceivedNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => array_merge([
                'notification_type' => 'chat_message_received',
                'title' => '新着メッセージ',
                'message' => 'コーチからメッセージが届きました',
                'url' => '/chat-rooms/1',
            ], $data),
            'read_at' => $unread ? null : $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $notification = $this->notify($this->student);

        $this->getJson(route('api.v1.notifications.index'))->assertUnauthorized();
        $this->postJson(route('api.v1.notifications.read', $notification))->assertUnauthorized();
        $this->postJson(route('api.v1.notifications.readAll'))->assertUnauthorized();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_index_returns_only_own_notifications_newest_first_with_unread_count(): void
    {
        $older = $this->notify($this->student, unread: false, at: now()->subHours(2));
        $newer = $this->notify($this->student, unread: true, data: ['title' => '最新'], at: now()->subMinutes(5));
        $this->notify(User::factory()->student()->create());

        $response = $this->actingAs($this->student)->getJson(route('api.v1.notifications.index'));

        $response->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('meta.tab', '全件')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.title', '最新')
            ->assertJsonPath('data.0.is_unread', true)
            ->assertJsonPath('data.0.type', 'chat_message_received')
            ->assertJsonPath('data.0.url', '/chat-rooms/1')
            ->assertJsonPath('data.0.created_at_human', '5分前')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.is_unread', false);
    }

    public function test_unread_tab_and_per_page(): void
    {
        foreach (range(1, 3) as $i) {
            $this->notify($this->student, unread: true, at: now()->subMinutes($i));
        }
        $this->notify($this->student, unread: false);

        $this->actingAs($this->student)
            ->getJson(route('api.v1.notifications.index', ['tab' => '未読のみ']))
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.tab', '未読のみ');

        $this->actingAs($this->student)
            ->getJson(route('api.v1.notifications.index', ['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.unread_count', 3);
    }

    /**
     * @dataProvider invalidQueryProvider
     *
     * @param array<string, mixed> $query
     */
    public function test_invalid_query_returns_422_json_in_japanese(array $query, string $field): void
    {
        $response = $this->actingAs($this->student)
            ->getJson(route('api.v1.notifications.index', $query))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertMatchesRegularExpression('/\p{Han}|\p{Hiragana}|\p{Katakana}/u', $response->json("errors.{$field}.0"));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'tab が「未読」' => [['tab' => '未読'], 'tab'],
            'tab が英字' => [['tab' => 'unread'], 'tab'],
            'per_page 0' => [['per_page' => 0], 'per_page'],
            'per_page 51' => [['per_page' => 51], 'per_page'],
            'per_page 非整数' => [['per_page' => 'abc'], 'per_page'],
        ];
    }

    public function test_per_page_boundaries_are_accepted(): void
    {
        foreach ([1, 50] as $perPage) {
            $this->actingAs($this->student)
                ->getJson(route('api.v1.notifications.index', ['per_page' => $perPage, 'tab' => '全件']))
                ->assertOk();
        }
    }

    public function test_admin_always_gets_empty_list(): void
    {
        $admin = User::factory()->admin()->create();
        $this->notify($admin);

        $this->actingAs($admin)
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_coach_can_list_own_notifications(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $this->notify($coach);

        $this->actingAs($coach)
            ->getJson(route('api.v1.notifications.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_read_marks_own_notification_and_returns_new_unread_count(): void
    {
        $target = $this->notify($this->student);
        $this->notify($this->student);

        $this->actingAs($this->student)
            ->postJson(route('api.v1.notifications.read', $target))
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.is_unread', false)
            ->assertJsonPath('meta.unread_count', 1);

        $this->assertNotNull($target->fresh()->read_at);
    }

    public function test_read_is_idempotent_for_already_read_notification(): void
    {
        $readAt = now()->subDay();
        $target = $this->notify($this->student, unread: false, at: $readAt);

        $this->actingAs($this->student)->postJson(route('api.v1.notifications.read', $target))->assertOk();

        $this->assertTrue($readAt->equalTo($target->fresh()->read_at));
    }

    public function test_reading_other_users_notification_returns_403(): void
    {
        $others = $this->notify(User::factory()->student()->create());

        $this->actingAs($this->student)
            ->postJson(route('api.v1.notifications.read', $others))
            ->assertForbidden();

        $this->assertNull($others->fresh()->read_at);
    }

    public function test_reading_unknown_notification_returns_404(): void
    {
        $this->actingAs($this->student)
            ->postJson(route('api.v1.notifications.read', ['notification' => (string) Str::uuid()]))
            ->assertNotFound();
    }

    public function test_read_all_marks_only_own_unread_notifications(): void
    {
        $mine = [$this->notify($this->student), $this->notify($this->student)];
        $others = $this->notify(User::factory()->student()->create());

        $this->actingAs($this->student)
            ->postJson(route('api.v1.notifications.readAll'))
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0)
            ->assertJsonPath('meta.updated_count', 2);

        foreach ($mine as $notification) {
            $this->assertNotNull($notification->fresh()->read_at);
        }
        $this->assertNull($others->fresh()->read_at);
    }
}
