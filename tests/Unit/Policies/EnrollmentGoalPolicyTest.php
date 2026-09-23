<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use App\Policies\EnrollmentGoalPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EnrollmentGoalPolicy の「受講生本人のみ操作可」を検証する。
 */
class EnrollmentGoalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const GOAL_ABILITIES = ['update', 'delete', 'markAchieved', 'unmarkAchieved'];

    public function test_owner_can_perform_all_abilities(): void
    {
        $policy = new EnrollmentGoalPolicy;
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $this->assertTrue($policy->create($student, $enrollment));
        foreach (self::GOAL_ABILITIES as $ability) {
            $this->assertTrue($policy->{$ability}($student, $goal), $ability);
        }
    }

    public function test_non_owner_is_denied_for_all_abilities(): void
    {
        $policy = new EnrollmentGoalPolicy;
        $enrollment = Enrollment::factory()->for(User::factory()->student())->create();
        $goal = EnrollmentGoal::factory()->forEnrollment($enrollment)->create();

        $actors = [
            User::factory()->student()->create(),
            User::factory()->coach()->create(),
            User::factory()->admin()->create(),
        ];

        foreach ($actors as $actor) {
            $this->assertFalse($policy->create($actor, $enrollment));
            foreach (self::GOAL_ABILITIES as $ability) {
                $this->assertFalse($policy->{$ability}($actor, $goal), $ability);
            }
        }
    }
}
