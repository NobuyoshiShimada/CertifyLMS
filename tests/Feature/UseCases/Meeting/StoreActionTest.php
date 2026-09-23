<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use App\UseCases\Meeting\StoreAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Fakes\FakeGoogleCalendarGateway;
use Tests\TestCase;

class StoreActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleCalendarGateway $gateway;

    private User $student;

    private Certification $certification;

    private Enrollment $enrollment;

    private Carbon $slot;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->gateway = new FakeGoogleCalendarGateway;
        $this->app->instance(GoogleCalendarGateway::class, $this->gateway);

        $this->slot = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 2, 'name' => '山田花子']);
        $this->certification = Certification::factory()->published()->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'user')->for($this->certification)->learning()->create();
    }

    private function assignCoach(): User
    {
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => 'https://meet.example.com/coach']);
        $this->certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(Carbon::MONDAY)->timeRange('09:00:00', '12:00:00')->create();

        return $coach;
    }

    private function store(): Meeting
    {
        return app(StoreAction::class)($this->enrollment, $this->slot->copy(), '午後問題の相談');
    }

    public function test_reserves_meeting_with_coach_consumes_quota_and_notifies_coach(): void
    {
        $coach = $this->assignCoach();

        $meeting = $this->store();

        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertSame($coach->id, $meeting->coach_id);
        $this->assertSame('https://meet.example.com/coach', $meeting->meeting_url_snapshot);
        $this->assertSame('午後問題の相談', $meeting->topic);
        $transaction = MeetingQuotaTransaction::query()->sole();
        $this->assertSame(MeetingQuotaTransactionType::Consumed, $transaction->type);
        $this->assertSame($transaction->id, $meeting->meeting_quota_transaction_id);
        Notification::assertSentTo($coach, MeetingReservedNotification::class);
    }

    public function test_assigns_least_loaded_coach_among_candidates(): void
    {
        $busy = $this->assignCoach();
        $free = $this->assignCoach();
        Meeting::factory()->completed()->forCoach($busy)->count(2)->create();

        $this->assertSame($free->id, $this->store()->coach_id);
    }

    public function test_creates_google_event_for_connected_coach(): void
    {
        $coach = $this->assignCoach();
        GoogleCredential::factory()->forCoach($coach)->create();

        $meeting = $this->store();

        $this->assertCount(1, $this->gateway->callsOf('create'));
        $this->assertNotNull($meeting->fresh()->google_event_id);
    }

    public function test_insufficient_quota_throws_without_side_effects(): void
    {
        $this->assignCoach();
        $this->student->update(['max_meetings' => 0]);

        try {
            $this->store();
            $this->fail('InsufficientMeetingQuotaException が投げられるはず');
        } catch (InsufficientMeetingQuotaException) {
        }

        $this->assertDatabaseCount('meetings', 0);
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_slot_outside_availability_throws(): void
    {
        $this->assignCoach();
        $this->slot->setTime(15, 0);

        $this->expectException(MeetingOutOfAvailabilityException::class);

        $this->store();
    }

    public function test_slot_collision_at_insert_is_converted_to_no_available_coach_without_consuming_quota(): void
    {
        // 候補抽出には現れない(canceled)が UNIQUE(coach_id, scheduled_at) に衝突する行 = 並行予約の後着と同じ状況
        $coach = $this->assignCoach();
        Meeting::factory()->canceled()->forCoach($coach)->create(['scheduled_at' => $this->slot]);

        try {
            $this->store();
            $this->fail('MeetingNoAvailableCoachException が投げられるはず');
        } catch (MeetingNoAvailableCoachException) {
        }

        $this->assertDatabaseCount('meeting_quota_transactions', 0);
        Notification::assertNothingSent();
    }

    public function test_notification_and_calendar_are_not_fired_when_outer_transaction_rolls_back(): void
    {
        $coach = $this->assignCoach();
        GoogleCredential::factory()->forCoach($coach)->create();

        try {
            DB::transaction(function () {
                $this->store();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('meetings', 0);
        Notification::assertNothingSent();
        $this->assertSame([], $this->gateway->callsOf('create'));
    }
}
