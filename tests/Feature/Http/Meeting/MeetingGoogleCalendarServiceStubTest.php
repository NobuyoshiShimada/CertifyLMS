<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 面談機能(空き枠集計 / 予約 / キャンセル)が Google カレンダー連携を「正しく呼ぶか」を、
 * 連携の窓口(GoogleCalendarService)ごとスタブ化して検証する。SDK やトークン管理の詳細はここでは扱わない。
 */
#[Group('external')]
class MeetingGoogleCalendarServiceStubTest extends TestCase
{
    use RefreshDatabase;

    /** @var GoogleCalendarService&MockInterface */
    private GoogleCalendarService $calendar;

    private User $student;

    private User $coach;

    private Enrollment $enrollment;

    private Carbon $monday;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->calendar = Mockery::mock(GoogleCalendarService::class);
        $this->app->instance(GoogleCalendarService::class, $this->calendar);

        $this->monday = now()->startOfDay()->next(Carbon::MONDAY);
        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $certification = Certification::factory()->published()->create();
        $this->coach = User::factory()->coach()->inProgress()->create();
        $certification->coaches()->attach($this->coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($this->coach)->onDay(Carbon::MONDAY)->timeRange('09:00:00', '12:00:00')->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'user')->for($certification)->learning()->create();
    }

    private function reserve(string $time): TestResponse
    {
        return $this->actingAs($this->student)->post(route('meetings.store', $this->enrollment), [
            'scheduled_at' => $this->monday->copy()->setTimeFromTimeString($time)->format('Y-m-d\TH:i:s'),
            'topic' => '相談',
        ]);
    }

    public function test_availability_excludes_slots_returned_as_busy_by_calendar(): void
    {
        // 空き枠集計は連携済コーチだけを問い合わせる
        GoogleCredential::factory()->forCoach($this->coach)->create();
        $this->calendar->shouldReceive('busyIntervals')->once()->andReturn([[
            'start' => $this->monday->copy()->setTime(10, 0),
            'end' => $this->monday->copy()->setTime(11, 0),
        ]]);

        $slots = $this->actingAs($this->student)
            ->getJson(route('meetings.availability', $this->enrollment).'?date='.$this->monday->format('Y-m-d'))
            ->assertOk()
            ->json('slots');

        $counts = collect($slots)->mapWithKeys(fn (array $slot) => [
            Carbon::parse($slot['slot_start'])->format('H:i') => $slot['available_coach_count'],
        ]);
        $this->assertSame(['09:00' => 1, '11:00' => 1], $counts->all());
    }

    public function test_reservation_checks_busy_then_creates_event_for_the_meeting(): void
    {
        $this->calendar->shouldReceive('isBusyAt')->once()->andReturnFalse();
        $this->calendar->shouldReceive('createEventFor')->once()->with(Mockery::on(
            fn (Meeting $meeting) => $meeting->coach_id === $this->coach->id && $meeting->status === MeetingStatus::Reserved,
        ));

        $this->reserve('10:00')->assertRedirect();

        $this->assertSame(1, Meeting::query()->where('status', MeetingStatus::Reserved->value)->count());
    }

    public function test_reservation_is_rejected_without_event_when_calendar_says_busy(): void
    {
        $this->calendar->shouldReceive('isBusyAt')->once()->andReturnTrue();
        $this->calendar->shouldNotReceive('createEventFor');

        $this->reserve('10:00')->assertSessionHas('error');

        $this->assertSame(0, Meeting::query()->count());
    }

    public function test_cancel_deletes_event_of_the_canceled_meeting(): void
    {
        $this->calendar->shouldReceive('isBusyAt')->andReturnFalse();
        $this->calendar->shouldReceive('createEventFor');
        $this->reserve('10:00');
        $meeting = Meeting::query()->sole();

        $this->calendar->shouldReceive('deleteEventFor')->once()->with(Mockery::on(fn (Meeting $m) => $m->id === $meeting->id));

        $this->actingAs($this->student)->post(route('meetings.cancel', $meeting))->assertRedirect();

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
    }
}
