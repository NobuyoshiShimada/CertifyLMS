<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use App\Policies\EnrollmentNotePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnrollmentNotePolicy の「閲覧 / 追加 = 担当コーチ + 管理者」「編集 / 削除 = 作成者 + 管理者」を検証する。
 */
class EnrollmentNotePolicyTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentNotePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new EnrollmentNotePolicy;
    }

    public function test_view_and_create_are_allowed_for_admin_and_assigned_coach_only(): void
    {
        $admin = User::factory()->admin()->create();
        $assigned = User::factory()->coach()->create();
        $unassigned = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($assigned->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $student = User::factory()->student()->create();
        $enrollment = Enrollment::factory()->for($student)->for($certification)->create();

        foreach (['viewAny', 'create'] as $ability) {
            $this->assertTrue($this->policy->{$ability}($admin, $enrollment), $ability);
            $this->assertTrue($this->policy->{$ability}($assigned, $enrollment), $ability);
            $this->assertFalse($this->policy->{$ability}($unassigned, $enrollment), $ability);
            $this->assertFalse($this->policy->{$ability}($student, $enrollment), $ability);
        }
    }

    public function test_update_and_delete_are_allowed_for_admin_and_author_only(): void
    {
        $admin = User::factory()->admin()->create();
        $author = User::factory()->coach()->create();
        $otherCoach = User::factory()->coach()->create();
        $student = User::factory()->student()->create();
        $note = EnrollmentNote::factory()
            ->forEnrollment(Enrollment::factory()->for($student)->create())
            ->byAuthor($author)
            ->create();

        foreach (['update', 'delete'] as $ability) {
            $this->assertTrue($this->policy->{$ability}($admin, $note), $ability);
            $this->assertTrue($this->policy->{$ability}($author, $note), $ability);
            $this->assertFalse($this->policy->{$ability}($otherCoach, $note), $ability);
            $this->assertFalse($this->policy->{$ability}($student, $note), $ability);
        }
    }
}
