<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Policies\PlanPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PlanPolicy のロール判定(admin のみ許可)を検証する。
 */
class PlanPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const PLAN_ABILITIES = ['view', 'update', 'delete', 'publish', 'archive', 'unarchive'];

    public function test_admin_can_perform_all_abilities(): void
    {
        $policy = new PlanPolicy;
        $admin = User::factory()->admin()->create();
        $plan = Plan::factory()->create();

        $this->assertTrue($policy->viewAny($admin));
        $this->assertTrue($policy->create($admin));
        foreach (self::PLAN_ABILITIES as $ability) {
            $this->assertTrue($policy->{$ability}($admin, $plan), $ability);
        }
    }

    public function test_student_and_coach_are_denied_for_all_abilities(): void
    {
        $policy = new PlanPolicy;
        $plan = Plan::factory()->create();

        foreach ([User::factory()->student()->create(), User::factory()->coach()->create()] as $user) {
            $this->assertFalse($policy->viewAny($user));
            $this->assertFalse($policy->create($user));
            foreach (self::PLAN_ABILITIES as $ability) {
                $this->assertFalse($policy->{$ability}($user, $plan), $ability);
            }
        }
    }
}
