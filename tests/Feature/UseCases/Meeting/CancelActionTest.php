<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakeGoogleCalendarGateway;
use Tests\TestCase;

class CancelActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleCalendarGateway $gateway;

    private User $coach;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->gateway = new FakeGoogleCalendarGateway;
        $this->app->instance(GoogleCalendarGateway::class, $this->gateway);
        $this->coach = User::factory()->coach()->inProgress()->create();
        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 2]);
    }

    private function reserved(array $attributes = []): Meeting
    {
        $meeting = Meeting::factory()->reserved()->forCoach($this->coach)->forStudent($this->student)->inFuture()->create($attributes);
        app(ConsumeQuotaAction::class)($this->student, $meeting->id);

        return $meeting;
    }

    public function test_student_cancel_refunds_quota_and_notifies_coach(): void
    {
        $meeting = $this->reserved();

        app(CancelAction::class)($meeting, $this->student);

        $meeting->refresh();
        $this->assertSame(MeetingStatus::Canceled, $meeting->status);
        $this->assertSame($this->student->id, $meeting->canceled_by_user_id);
        $this->assertNotNull($meeting->canceled_at);
        $this->assertSame(1, MeetingQuotaTransaction::query()->where('type', MeetingQuotaTransactionType::Refunded)->count());
        Notification::assertSentTo($this->coach, MeetingCanceledNotification::class);
        Notification::assertNotSentTo($this->student, MeetingCanceledNotification::class);
    }

    public function test_coach_cancel_refunds_to_student_and_notifies_student(): void
    {
        $meeting = $this->reserved();

        app(CancelAction::class)($meeting, $this->coach);

        $refund = MeetingQuotaTransaction::query()->where('type', MeetingQuotaTransactionType::Refunded)->sole();
        $this->assertSame($this->student->id, $refund->user_id);
        Notification::assertSentTo($this->student, MeetingCanceledNotification::class);
    }

    public function test_deletes_google_event_after_cancel(): void
    {
        GoogleCredential::factory()->forCoach($this->coach)->create();
        $meeting = $this->reserved(['google_event_id' => 'evt_1']);

        app(CancelAction::class)($meeting, $this->student);

        $this->assertCount(1, $this->gateway->callsOf('delete'));
        $this->assertNull($meeting->fresh()->google_event_id);
    }

    public function test_non_reserved_meeting_cannot_be_canceled(): void
    {
        $meeting = Meeting::factory()->completed()->forCoach($this->coach)->forStudent($this->student)->create();

        try {
            app(CancelAction::class)($meeting, $this->student);
            $this->fail('MeetingStatusTransitionException が投げられるはず');
        } catch (MeetingStatusTransitionException) {
        }

        $this->assertSame(MeetingStatus::Completed, $meeting->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_started_meeting_cannot_be_canceled_and_nothing_is_refunded(): void
    {
        $meeting = $this->reserved(['scheduled_at' => now()->subMinute()]);

        try {
            app(CancelAction::class)($meeting, $this->student);
            $this->fail('MeetingAlreadyStartedException が投げられるはず');
        } catch (MeetingAlreadyStartedException) {
        }

        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertSame(0, MeetingQuotaTransaction::query()->where('type', MeetingQuotaTransactionType::Refunded)->count());
        Notification::assertNothingSent();
    }
}
