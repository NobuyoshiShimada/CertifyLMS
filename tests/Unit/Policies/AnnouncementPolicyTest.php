<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;
use App\Policies\AnnouncementPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AnnouncementPolicy の認可ルール（管理者のみ操作可）を検証する単体テスト。
 */
class AnnouncementPolicyTest extends TestCase
{
    use RefreshDatabase;

    private AnnouncementPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new AnnouncementPolicy;
    }

    public function test_admin_can_view_any_create_and_view(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        /** @var Announcement $announcement */
        $announcement = Announcement::factory()->create(['created_by' => $admin->id]);

        $this->assertTrue($this->policy->viewAny($admin));
        $this->assertTrue($this->policy->create($admin));
        $this->assertTrue($this->policy->view($admin, $announcement));
    }

    public function test_non_admin_is_denied_for_all_abilities(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        /** @var Announcement $announcement */
        $announcement = Announcement::factory()->create(['created_by' => $admin->id]);

        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);
        /** @var User $coach */
        $coach = User::factory()->create(['role' => UserRole::Coach]);

        foreach ([$student, $coach] as $nonAdmin) {
            $this->assertFalse($this->policy->viewAny($nonAdmin));
            $this->assertFalse($this->policy->create($nonAdmin));
            $this->assertFalse($this->policy->view($nonAdmin, $announcement));
        }
    }
}
