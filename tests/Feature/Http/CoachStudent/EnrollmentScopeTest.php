<?php

declare(strict_types=1);

namespace Tests\Feature\Http\CoachStudent;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B-B-15 回帰テスト: 受講登録一覧の表示範囲がロールごとに正しいことを検証する。
 * コーチ = 担当割り当て資格の受講登録のみ / 管理者 = 全件 / 受講生 = 自分の受講登録のみ。
 */
class EnrollmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $coachA;

    private User $coachB;

    private Enrollment $onlyA;

    private Enrollment $shared;

    private Enrollment $onlyB;

    private Enrollment $unassigned;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->coachA = User::factory()->coach()->create();
        $this->coachB = User::factory()->coach()->create();

        $certOnlyA = $this->certificationAssignedTo([$this->coachA]);
        $certShared = $this->certificationAssignedTo([$this->coachA, $this->coachB]);
        $certOnlyB = $this->certificationAssignedTo([$this->coachB]);
        $certUnassigned = $this->certificationAssignedTo([]);

        $this->student = User::factory()->student()->inProgress()->create();
        $this->onlyA = Enrollment::factory()->learning()->for($this->student)->for($certOnlyA)->create();
        $this->shared = Enrollment::factory()->learning()->for(User::factory()->student()->inProgress())->for($certShared)->create();
        $this->onlyB = Enrollment::factory()->learning()->for(User::factory()->student()->inProgress())->for($certOnlyB)->create();
        $this->unassigned = Enrollment::factory()->learning()->for(User::factory()->student()->inProgress())->for($certUnassigned)->create();
    }

    /**
     * @param array<int, User> $coaches
     */
    private function certificationAssignedTo(array $coaches): Certification
    {
        $certification = Certification::factory()->published()->create();
        foreach ($coaches as $coach) {
            $certification->coaches()->attach($coach->id, [
                'id' => (string) Str::ulid(),
                'assigned_by_user_id' => $this->admin->id,
                'assigned_at' => now(),
            ]);
        }

        return $certification;
    }

    /**
     * @return array<int, string>
     */
    private function listedIds(User $viewer): array
    {
        return collect(
            $this->actingAs($viewer)->get(route('enrollments.index'))->assertOk()->viewData('enrollments')->items()
        )->pluck('id')->all();
    }

    public function test_coach_sees_only_enrollments_in_assigned_certifications(): void
    {
        $this->assertEqualsCanonicalizing([$this->onlyA->id, $this->shared->id], $this->listedIds($this->coachA));
        $this->assertEqualsCanonicalizing([$this->shared->id, $this->onlyB->id], $this->listedIds($this->coachB));
    }

    public function test_coach_without_assignments_sees_nothing(): void
    {
        $this->assertSame([], $this->listedIds(User::factory()->coach()->create()));
    }

    public function test_admin_still_sees_all_enrollments(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->onlyA->id, $this->shared->id, $this->onlyB->id, $this->unassigned->id],
            $this->listedIds($this->admin),
        );
    }

    public function test_student_still_sees_only_own_enrollments(): void
    {
        $ids = $this->actingAs($this->student)
            ->get(route('enrollments.index'))
            ->assertOk()
            ->viewData('enrollments')
            ->pluck('id')
            ->all();

        $this->assertSame([$this->onlyA->id], $ids);
    }
}
