<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Certification;
use App\Models\CoachAvailability;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\FetchAvailabilityAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FetchAvailabilityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_hourly_slots_with_available_coach_count_excluding_booked(): void
    {
        $monday = now()->startOfDay()->next(Carbon::MONDAY);
        $certification = Certification::factory()->published()->create();
        $coaches = User::factory()->coach()->inProgress()->count(2)->create();
        foreach ($coaches as $coach) {
            $certification->coaches()->attach($coach->id, [
                'id' => (string) Str::ulid(),
                'assigned_by_user_id' => User::factory()->admin()->create()->id,
                'assigned_at' => now(),
                'unassigned_at' => null,
            ]);
            CoachAvailability::factory()->forCoach($coach)->onDay(Carbon::MONDAY)->timeRange('09:00:00', '11:00:00')->create();
        }
        Meeting::factory()->reserved()->forCoach($coaches[0])->create(['scheduled_at' => $monday->copy()->setTime(10, 0)]);
        $enrollment = Enrollment::factory()->for($certification)->learning()->create();

        $slots = app(FetchAvailabilityAction::class)($enrollment, $monday);

        $this->assertSame(
            ['09:00' => 2, '10:00' => 1],
            $slots->mapWithKeys(fn (array $slot) => [$slot['slot_start']->format('H:i') => $slot['available_coach_count']])->all(),
        );
        $this->assertSame('10:00', $slots->first()['slot_end']->format('H:i'));
    }

    public function test_day_without_availability_returns_no_slots(): void
    {
        $enrollment = Enrollment::factory()->learning()->create();

        $this->assertTrue(app(FetchAvailabilityAction::class)($enrollment, now()->next(Carbon::SUNDAY))->isEmpty());
    }
}
