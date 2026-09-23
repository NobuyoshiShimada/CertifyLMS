<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\InvitationMail;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use App\Notifications\BaseNotification;
use App\Notifications\ChatMessageReceivedNotification;
use App\Notifications\MeetingCanceledNotification;
use App\Notifications\MeetingReminderNotification;
use App\Notifications\MeetingReservedNotification;
use App\Notifications\NewQuestionPostedNotification;
use App\Notifications\QuestionRepliedNotification;
use App\UseCases\Auth\IssueInvitationAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T-A-05 回帰テスト: 通知・招待メールがキューへ投入され(同期送信されない)、リトライ設定を持つことを検証する。
 *
 * Queue::fake() は接続設定に依らず投入を記録するため、ここで保証できるのは「ジョブとして投入される」まで。
 * worker による実際の非同期処理は README の手順で動作確認する。
 */
class NotificationQueueingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<BaseNotification>}>
     */
    public static function notificationClasses(): array
    {
        return [
            'お知らせ' => [AdminAnnouncementNotification::class],
            'チャット' => [ChatMessageReceivedNotification::class],
            '面談キャンセル' => [MeetingCanceledNotification::class],
            '面談リマインダー' => [MeetingReminderNotification::class],
            '面談予約' => [MeetingReservedNotification::class],
            'Q&A 新規質問' => [NewQuestionPostedNotification::class],
            'Q&A 返信' => [QuestionRepliedNotification::class],
        ];
    }

    /**
     * @param class-string<BaseNotification> $class
     */
    #[DataProvider('notificationClasses')]
    public function test_every_business_notification_extends_queued_base(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, BaseNotification::class));
        $this->assertTrue(is_subclass_of($class, ShouldQueue::class));
    }

    public function test_retry_settings_back_off_progressively(): void
    {
        $notification = new AdminAnnouncementNotification(['title' => 't', 'body' => 'b']);
        $mail = (new \ReflectionClass(InvitationMail::class))->newInstanceWithoutConstructor();

        foreach ([$notification, $mail] as $queueable) {
            $this->assertSame(3, $queueable->tries);
            $backoff = $queueable->backoff();
            $sorted = $backoff;
            sort($sorted);
            $this->assertSame($sorted, $backoff, '待機秒数は試行ごとに伸びる');
            $this->assertGreaterThan($backoff[0], end($backoff));
        }
    }

    public function test_database_queue_dispatches_after_commit(): void
    {
        $this->assertTrue(config('queue.connections.database.after_commit'));
    }

    public function test_announcement_broadcast_only_queues_jobs_without_sending_synchronously(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->count(3)->create(['role' => UserRole::Student, 'status' => UserStatus::InProgress]);

        $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '一斉配信',
            'body' => '本文',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ])->assertSessionHas('success');

        // 受講生 3 人 × チャネル 2(database / mail) = 6 件の独立したジョブ
        Queue::assertPushed(SendQueuedNotifications::class, 6);
        // リクエスト内では送信されていない(DB 通知もまだ作られない)
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_invitation_mail_is_queued(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        app(IssueInvitationAction::class)('queued@example.test', UserRole::Student, $plan, $admin);

        Queue::assertPushed(SendQueuedMailable::class, fn (SendQueuedMailable $job) => $job->mailable instanceof InvitationMail);
    }
}
