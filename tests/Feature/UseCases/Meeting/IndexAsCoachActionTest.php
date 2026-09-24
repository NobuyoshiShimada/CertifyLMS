<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAsCoachAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexAsCoachActionTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = User::factory()->coach()->inProgress()->create();
    }

    public function test_upcoming_is_default_and_sorted_soonest_first(): void
    {
        $later = Meeting::factory()->reserved()->forCoach($this->coach)->create(['scheduled_at' => now()->addDays(5)->setTime(10, 0)]);
        $sooner = Meeting::factory()->reserved()->forCoach($this->coach)->create(['scheduled_at' => now()->addDays(1)->setTime(10, 0)]);
        Meeting::factory()->completed()->forCoach($this->coach)->create();
        Meeting::factory()->reserved()->inFuture()->create(); // 他コーチ

        $result = app(IndexAsCoachAction::class)($this->coach, null);

        $this->assertSame('upcoming', $result->filter);
        $this->assertSame([$sooner->id, $later->id], $result->meetings->pluck('id')->all());
        $this->assertTrue($result->meetings->first()->relationLoaded('student'));
    }

    public function test_past_filter_is_sorted_latest_first(): void
    {
        $older = Meeting::factory()->completed()->forCoach($this->coach)->create(['scheduled_at' => now()->subDays(5)->setTime(10, 0)]);
        $newer = Meeting::factory()->canceled()->forCoach($this->coach)->create(['scheduled_at' => now()->subDays(1)->setTime(10, 0)]);

        $result = app(IndexAsCoachAction::class)($this->coach, 'past');

        $this->assertSame([$newer->id, $older->id], $result->meetings->pluck('id')->all());
    }

    public function test_filters_by_student_and_enrollment(): void
    {
        $target = Meeting::factory()->reserved()->inFuture()->forCoach($this->coach)->create();
        $sameStudentOtherEnrollment = Meeting::factory()->reserved()->inFuture()->forCoach($this->coach)->forStudent($target->student)->create();
        Meeting::factory()->reserved()->inFuture()->forCoach($this->coach)->create();

        $byStudent = app(IndexAsCoachAction::class)($this->coach, 'all', $target->student_id);
        $this->assertEqualsCanonicalizing(
            [$target->id, $sameStudentOtherEnrollment->id],
            $byStudent->meetings->pluck('id')->all(),
        );

        $byEnrollment = app(IndexAsCoachAction::class)($this->coach, 'all', null, $target->enrollment_id);
        $this->assertSame([$target->id], $byEnrollment->meetings->pluck('id')->all());
    }
}
