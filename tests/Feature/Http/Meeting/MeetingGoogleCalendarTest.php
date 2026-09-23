<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeGoogleCalendarGateway;
use Tests\TestCase;

/**
 * 面談の空き枠 / 予約 / キャンセルと Google カレンダー連携(freebusy 除外・Event 作成 / 削除・フォールバック・
 * トークン自動更新)を検証する機能テスト。Google API は偽の Gateway に差し替える。
 */
class MeetingGoogleCalendarTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleCalendarGateway $gateway;

    private User $student;

    private User $connectedCoach;

    private Certification $certification;

    private Enrollment $enrollment;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGoogleCalendarGateway;
        $this->app->instance(GoogleCalendarGateway::class, $this->gateway);

        $this->monday = now()->startOfDay()->next(Carbon::MONDAY);
        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 5, 'name' => '山田花子']);
        $this->certification = Certification::factory()->published()->create(['name' => '基本情報技術者']);
        $this->connectedCoach = $this->coach('https://meet.example.com/connected');
        GoogleCredential::factory()->forCoach($this->connectedCoach)->create(['access_token' => 'token-connected']);
        $this->enrollment = Enrollment::factory()->for($this->student, 'user')->for($this->certification)->learning()->create();
    }

    private function coach(?string $meetingUrl = null): User
    {
        $coach = User::factory()->coach()->inProgress()->create(['meeting_url' => $meetingUrl]);
        $this->certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($coach)->onDay(Carbon::MONDAY)->timeRange('09:00:00', '12:00:00')->create();

        return $coach;
    }

    private function busyAt(string $token, string $from, string $to): void
    {
        $this->gateway->busyByToken[$token][] = [
            'start' => $this->monday->copy()->setTimeFromTimeString($from),
            'end' => $this->monday->copy()->setTimeFromTimeString($to),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function slotCounts(): array
    {
        $slots = $this->actingAs($this->student)
            ->getJson(route('meetings.availability', $this->enrollment).'?date='.$this->monday->format('Y-m-d'))
            ->assertOk()
            ->json('slots');

        return collect($slots)->mapWithKeys(fn (array $slot) => [
            Carbon::parse($slot['slot_start'])->format('H:i') => $slot['available_coach_count'],
        ])->all();
    }

    private function reserve(string $time): TestResponse
    {
        return $this->actingAs($this->student)->post(route('meetings.store', $this->enrollment), [
            'scheduled_at' => $this->monday->copy()->setTimeFromTimeString($time)->format('Y-m-d\TH:i:s'),
            'topic' => '午後問題の対策',
        ]);
    }

    public function test_busy_time_of_connected_coach_is_excluded_from_slots(): void
    {
        $this->busyAt('token-connected', '10:00', '10:30');

        $this->assertSame(['09:00' => 1, '11:00' => 1], $this->slotCounts());
        $this->assertCount(1, $this->gateway->callsOf('busy'));
    }

    public function test_unconnected_coach_keeps_legacy_slots_and_is_not_queried(): void
    {
        $this->coach();
        $this->busyAt('token-connected', '10:00', '11:00');

        $this->assertSame(['09:00' => 2, '10:00' => 1, '11:00' => 2], $this->slotCounts());
        $this->assertCount(1, $this->gateway->callsOf('busy'));
    }

    public function test_freebusy_is_requested_once_per_connected_coach(): void
    {
        $second = $this->coach();
        GoogleCredential::factory()->forCoach($second)->create(['access_token' => 'token-second']);

        $this->slotCounts();

        $this->assertEqualsCanonicalizing(
            ['token-connected', 'token-second'],
            array_column($this->gateway->callsOf('busy'), 'token'),
        );
    }

    public function test_freebusy_failure_falls_back_to_no_busy_time(): void
    {
        $this->gateway->failBusy = true;

        $this->assertSame(['09:00' => 1, '10:00' => 1, '11:00' => 1], $this->slotCounts());
    }

    public function test_reservation_skips_coach_busy_on_google_calendar(): void
    {
        $free = $this->coach();
        $this->busyAt('token-connected', '10:00', '11:00');

        $this->reserve('10:00')->assertRedirect();

        $this->assertSame($free->id, Meeting::sole()->coach_id);
    }

    public function test_reservation_creates_event_for_60_minutes_with_snapshot_url(): void
    {
        $this->reserve('10:00')->assertRedirect();

        $meeting = Meeting::sole();
        $create = $this->gateway->callsOf('create');
        $this->assertCount(1, $create);
        $payload = $create[0]['payload'];
        $this->assertTrue($meeting->scheduled_at->equalTo($payload->start));
        $this->assertTrue($meeting->scheduled_at->copy()->addMinutes(60)->equalTo($payload->end));
        $this->assertSame('https://meet.example.com/connected', $payload->location);
        $this->assertStringContainsString('山田花子', $payload->summary);
        $this->assertStringContainsString('基本情報技術者', $payload->summary);
        $this->assertStringContainsString('午後問題の対策', $payload->description);
        $this->assertStringContainsString('https://meet.example.com/connected', $payload->description);
        $this->assertNotNull($meeting->google_event_id);
        $this->assertStringStartsWith('evt_', $meeting->google_event_id);
    }

    public function test_reservation_for_unconnected_coach_creates_no_event(): void
    {
        GoogleCredential::query()->delete();

        $this->reserve('10:00')->assertRedirect();

        $this->assertSame([], $this->gateway->callsOf('create'));
        $this->assertNull(Meeting::sole()->google_event_id);
    }

    public function test_event_creation_failure_keeps_reservation_quota_and_notification(): void
    {
        Notification::fake();
        $this->gateway->failCreate = true;

        $this->reserve('10:00')->assertRedirect()->assertSessionHas('success');

        $meeting = Meeting::sole();
        $this->assertSame(MeetingStatus::Reserved, $meeting->status);
        $this->assertNull($meeting->google_event_id);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $this->student->id,
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Consumed->value,
        ]);
        Notification::assertSentTo($this->connectedCoach, MeetingReservedNotification::class);
    }

    private function reservedMeetingWithEvent(): Meeting
    {
        $meeting = Meeting::factory()->reserved()->forCoach($this->connectedCoach)->forStudent($this->student)->create([
            'enrollment_id' => $this->enrollment->id,
            'scheduled_at' => $this->monday->copy()->setTime(10, 0),
            'google_event_id' => 'evt_existing',
        ]);
        app(ConsumeQuotaAction::class)($this->student, $meeting->id);

        return $meeting;
    }

    public function test_cancel_deletes_google_event(): void
    {
        $meeting = $this->reservedMeetingWithEvent();

        $this->actingAs($this->student)->post(route('meetings.cancel', $meeting))->assertRedirect();

        $delete = $this->gateway->callsOf('delete');
        $this->assertCount(1, $delete);
        $this->assertSame('evt_existing', $delete[0]['event_id']);
        $this->assertNull($meeting->fresh()->google_event_id);
    }

    public function test_event_deletion_failure_keeps_cancellation_and_refund(): void
    {
        $this->gateway->failDelete = true;
        $meeting = $this->reservedMeetingWithEvent();

        $this->actingAs($this->student)->post(route('meetings.cancel', $meeting))->assertRedirect()->assertSessionHas('success');

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'related_meeting_id' => $meeting->id,
            'type' => MeetingQuotaTransactionType::Refunded->value,
        ]);
        $this->assertSame('evt_existing', $meeting->fresh()->google_event_id);
    }

    public function test_cancel_after_disconnect_does_not_call_google_and_keeps_event_id(): void
    {
        $meeting = $this->reservedMeetingWithEvent();
        GoogleCredential::query()->delete();

        $this->actingAs($this->student)->post(route('meetings.cancel', $meeting))->assertRedirect();

        $this->assertSame([], $this->gateway->callsOf('delete'));
        $this->assertSame('evt_existing', $meeting->fresh()->google_event_id);
    }

    public function test_expired_access_token_is_refreshed_before_calling_api(): void
    {
        $this->connectedCoach->googleCredential->update(['token_expires_at' => now()->subMinutes(5)]);
        $this->busyAt('refreshed-access-token', '10:00', '11:00');

        $this->assertSame(['09:00' => 1, '11:00' => 1], $this->slotCounts());

        $this->assertCount(1, $this->gateway->callsOf('refresh'));
        $credential = $this->connectedCoach->googleCredential->fresh();
        $this->assertSame('refreshed-access-token', $credential->access_token);
        $this->assertTrue($credential->token_expires_at->isFuture());
    }

    public function test_401_from_api_triggers_refresh_and_retry(): void
    {
        $this->gateway->expireOnce = true;

        $this->reserve('10:00')->assertRedirect();

        $this->assertCount(1, $this->gateway->callsOf('refresh'));
        $this->assertNotNull(Meeting::sole()->google_event_id);
    }

    public function test_refresh_failure_falls_back_to_unconnected_behavior(): void
    {
        $this->connectedCoach->googleCredential->update(['token_expires_at' => now()->subMinutes(5)]);
        $this->gateway->failRefresh = true;

        $this->assertSame(['09:00' => 1, '10:00' => 1, '11:00' => 1], $this->slotCounts());

        $this->reserve('10:00')->assertRedirect()->assertSessionHas('success');
        $this->assertSame(MeetingStatus::Reserved, Meeting::sole()->status);
        $this->assertNull(Meeting::sole()->google_event_id);
    }
}
