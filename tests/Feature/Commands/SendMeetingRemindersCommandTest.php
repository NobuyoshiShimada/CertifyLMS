<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * `notifications:send-meeting-reminders` の対象解決・冪等性・利用状態フィルタを検証する機能テスト。
 */
class SendMeetingRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createMeeting(array $overrides = []): Meeting
    {
        /** @var User $coach */
        $coach = User::factory()->coach()->create(['status' => UserStatus::InProgress]);
        /** @var User $student */
        $student = User::factory()->student()->create(['status' => UserStatus::InProgress]);
        $enrollment = Enrollment::factory()->learning()->for($student, 'user')->create();

        return Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->forEnrollment($enrollment)
            ->create($overrides);
    }

    public function test_eve_window_sends_reminder_to_coach_and_student_for_meetings_scheduled_tomorrow(): void
    {
        Notification::fake();

        $meeting = $this->createMeeting(['scheduled_at' => now()->addDay()->setTime(15, 0)]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->assertExitCode(0);

        Notification::assertSentTo([$meeting->coach, $meeting->student], MeetingReminderNotification::class);
        $this->assertNotNull($meeting->fresh()->eve_reminder_sent_at);
        $this->assertNull($meeting->fresh()->one_hour_before_reminder_sent_at);
    }

    public function test_eve_window_ignores_meetings_not_scheduled_tomorrow(): void
    {
        Notification::fake();

        $farMeeting = $this->createMeeting(['scheduled_at' => now()->addDays(5)->setTime(15, 0)]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'eve'])->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($farMeeting->fresh()->eve_reminder_sent_at);
    }

    public function test_one_hour_before_window_sends_reminder_for_meetings_starting_within_the_hour(): void
    {
        Notification::fake();

        $meeting = $this->createMeeting(['scheduled_at' => now()->addMinutes(45)]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])->assertExitCode(0);

        Notification::assertSentTo([$meeting->coach, $meeting->student], MeetingReminderNotification::class);
        $this->assertNotNull($meeting->fresh()->one_hour_before_reminder_sent_at);
    }

    public function test_one_hour_before_window_ignores_meetings_starting_beyond_the_hour(): void
    {
        Notification::fake();

        $laterMeeting = $this->createMeeting(['scheduled_at' => now()->addHours(3)]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($laterMeeting->fresh()->one_hour_before_reminder_sent_at);
    }

    public function test_ignores_canceled_and_completed_meetings(): void
    {
        Notification::fake();

        $canceled = $this->createMeeting([
            'scheduled_at' => now()->addMinutes(30),
            'status' => MeetingStatus::Canceled->value,
        ]);
        $completed = $this->createMeeting([
            'scheduled_at' => now()->addMinutes(30),
            'status' => MeetingStatus::Completed->value,
        ]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])->assertExitCode(0);

        Notification::assertNothingSent();
        $this->assertNull($canceled->fresh()->one_hour_before_reminder_sent_at);
        $this->assertNull($completed->fresh()->one_hour_before_reminder_sent_at);
    }

    public function test_does_not_notify_withdrawn_recipient(): void
    {
        Notification::fake();

        /** @var User $coach */
        $coach = User::factory()->coach()->create(['status' => UserStatus::Withdrawn]);
        /** @var User $student */
        $student = User::factory()->student()->create(['status' => UserStatus::InProgress]);
        $enrollment = Enrollment::factory()->learning()->for($student, 'user')->create();

        $meeting = Meeting::factory()
            ->reserved()
            ->forCoach($coach)
            ->forStudent($student)
            ->forEnrollment($enrollment)
            ->create(['scheduled_at' => now()->addMinutes(30)]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])->assertExitCode(0);

        Notification::assertSentTo($student, MeetingReminderNotification::class);
        Notification::assertNotSentTo($coach, MeetingReminderNotification::class);
    }

    public function test_does_not_send_duplicate_reminder_when_run_twice(): void
    {
        Notification::fake();

        $meeting = $this->createMeeting(['scheduled_at' => now()->addMinutes(30)]);

        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])->assertExitCode(0);
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'one_hour_before'])->assertExitCode(0);

        Notification::assertSentToTimes($meeting->fresh()->coach, MeetingReminderNotification::class, 1);
        Notification::assertSentToTimes($meeting->fresh()->student, MeetingReminderNotification::class, 1);
    }

    public function test_invalid_window_option_fails(): void
    {
        $this->artisan('notifications:send-meeting-reminders', ['--window' => 'invalid'])->assertExitCode(1);
    }
}
