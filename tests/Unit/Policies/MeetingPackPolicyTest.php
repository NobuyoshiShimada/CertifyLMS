<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;
use App\Policies\MeetingPackPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingPackPolicy の認可ルール(管理者のみ操作可)を検証する単体テスト。
 */
class MeetingPackPolicyTest extends TestCase
{
    use RefreshDatabase;

    private MeetingPackPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new MeetingPackPolicy;
    }

    public function test_admin_can_perform_all_abilities(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        /** @var MeetingPack $plan */
        $plan = MeetingPack::factory()->create(['created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);

        $this->assertTrue($this->policy->viewAny($admin));
        $this->assertTrue($this->policy->view($admin, $plan));
        $this->assertTrue($this->policy->create($admin));
        $this->assertTrue($this->policy->update($admin, $plan));
        $this->assertTrue($this->policy->delete($admin, $plan));
        $this->assertTrue($this->policy->publish($admin, $plan));
        $this->assertTrue($this->policy->archive($admin, $plan));
        $this->assertTrue($this->policy->unarchive($admin, $plan));
    }

    public function test_non_admin_is_denied_for_all_abilities(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        /** @var MeetingPack $plan */
        $plan = MeetingPack::factory()->create(['created_by_user_id' => $admin->id, 'updated_by_user_id' => $admin->id]);

        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);
        /** @var User $coach */
        $coach = User::factory()->create(['role' => UserRole::Coach]);

        foreach ([$student, $coach] as $nonAdmin) {
            $this->assertFalse($this->policy->viewAny($nonAdmin));
            $this->assertFalse($this->policy->view($nonAdmin, $plan));
            $this->assertFalse($this->policy->create($nonAdmin));
            $this->assertFalse($this->policy->update($nonAdmin, $plan));
            $this->assertFalse($this->policy->delete($nonAdmin, $plan));
            $this->assertFalse($this->policy->publish($nonAdmin, $plan));
            $this->assertFalse($this->policy->archive($nonAdmin, $plan));
            $this->assertFalse($this->policy->unarchive($nonAdmin, $plan));
        }
    }
}
