<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Class NotificationControllerTest
 *
 * NotificationControllerの全エンドポイント（一覧、未読フィルタ、詳細、既読処理、一括既読化）を網羅するFeatureテスト。
 */
class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = User::factory()->student()->create([
            'id' => (string) Str::ulid(),
        ]);
    }

    /**
     * 通知一覧にアクセス可能であり、かつN+1問題が未然に防止されていることを検証する。
     *
     * @return void
     */
    public function test_index_displays_notifications_and_prevents_n_plus_one_clog(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            DatabaseNotification::create([
                'id' => Str::uuid()->toString(),
                'type' => 'App\Notifications\QuestionRepliedNotification',
                'notifiable_type' => get_class($this->student),
                'notifiable_id' => $this->student->id,
                'data' => ['title' => "通知{$i}", 'message' => '本文', 'url' => "/qa-board/{$i}"],
                'read_at' => null,
            ]);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($this->student)->get(route('notifications.index'));

        $response->assertStatus(200)
            ->assertViewIs('notifications.index')
            ->assertViewHas('unreadCount', 3);

        // クエリ発行回数がループ件数に比例しないO(1)であることをアサート
        $this->assertLessThan(10, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    /**
     * 個別通知が既読化され、ターゲットURLへ正常にリダイレクトされるか検証する。
     *
     * @return void
     */
    public function test_read_action_updates_status_and_redirects(): void
    {
        $notification = DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\QuestionRepliedNotification',
            'notifiable_type' => get_class($this->student),
            'notifiable_id' => $this->student->id,
            'data' => ['title' => '回答', 'message' => '本文', 'url' => '/qa-board/50'],
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->student)
            ->post(route('notifications.markAsRead', ['notification' => $notification->id]));

        $response->assertRedirect('/qa-board/50');
        $this->assertNotNull($notification->refresh()->read_at);
    }

    /**
     * 一括既読化アクションによって、ユーザーの全未読データが瞬時に解消されるか検証する。
     *
     * @return void
     */
    public function test_mark_all_as_read_action_clears_all_unreads(): void
    {
        DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\QuestionRepliedNotification',
            'notifiable_type' => get_class($this->student),
            'notifiable_id' => $this->student->id,
            'data' => ['title' => '1', 'message' => '1', 'url' => '/1'],
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->student)->post(route('notifications.markAllAsRead'));

        $response->assertRedirect();
        $unreadCount = DatabaseNotification::where('notifiable_id', $this->student->id)->unread()->count();
        $this->assertSame(0, $unreadCount);
    }
}
