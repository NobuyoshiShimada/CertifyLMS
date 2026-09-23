<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingStatus;
use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B-A-01 回帰テスト: 同一コーチ × 同一時刻の面談は DB レベルで 1 件しか成立せず、
 * 予約 API はその衝突を「空きコーチなし」(409)として返すことを検証する。
 */
class MeetingDoubleBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private Carbon $slot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = User::factory()->coach()->inProgress()->create();
        $this->slot = now()->startOfDay()->next(Carbon::MONDAY)->setTime(10, 0);
    }

    public function test_database_rejects_second_meeting_for_same_coach_and_slot(): void
    {
        Meeting::factory()->reserved()->forCoach($this->coach)->create(['scheduled_at' => $this->slot]);

        $this->expectException(UniqueConstraintViolationException::class);

        // 並行予約で候補抽出をすり抜けた 2 件目の INSERT に相当
        Meeting::factory()->reserved()->forCoach($this->coach)->create(['scheduled_at' => $this->slot]);
    }

    public function test_same_slot_with_different_coaches_or_times_is_allowed(): void
    {
        Meeting::factory()->reserved()->forCoach($this->coach)->create(['scheduled_at' => $this->slot]);
        Meeting::factory()->reserved()->forCoach(User::factory()->coach()->create())->create(['scheduled_at' => $this->slot]);
        Meeting::factory()->reserved()->forCoach($this->coach)->create(['scheduled_at' => $this->slot->copy()->addHour()]);

        $this->assertSame(3, Meeting::query()->count());
    }

    public function test_store_returns_409_json_when_slot_collides_at_database_level(): void
    {
        $student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($this->coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        CoachAvailability::factory()->forCoach($this->coach)->onDay(1)->timeRange('09:00:00', '18:00:00')->create();
        $enrollment = Enrollment::factory()->for($student, 'user')->for($certification)->learning()->create();
        // 候補抽出には現れない(canceled)が、同一コーチ × 同一時刻の行が既にある = 並行予約の衝突と同じ状況
        Meeting::factory()->canceled()->forCoach($this->coach)->create(['scheduled_at' => $this->slot]);

        $this->actingAs($student)
            ->postJson(route('meetings.store', $enrollment), [
                'scheduled_at' => $this->slot->format('Y-m-d\TH:i:s'),
                'topic' => '相談したい',
            ])
            ->assertStatus(409);

        $this->assertSame(0, Meeting::query()->where('status', MeetingStatus::Reserved->value)->count());
        // 面談回数は消費されない(トランザクションごと巻き戻る)
        $this->assertDatabaseCount('meeting_quota_transactions', 0);
    }
}
