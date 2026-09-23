<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\LearningSession;
use App\Models\User;
use App\UseCases\Dashboard\FetchCoachDashboardAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T-B-01 回帰テスト: コーチダッシュボードの担当受講生一覧が、クエリ最適化後も
 * 表示内容(受講生 / 資格 / 最終活動日時)・絞り込みを変えずに返すことと、
 * 最終活動日時を学習セッション全件の読み込みではなく集約で取得していることを検証する。
 */
class CoachAssignedEnrollmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private Certification $assigned;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));
        $this->coach = User::factory()->coach()->inProgress()->create();
        $this->assigned = Certification::factory()->published()->create(['name' => '担当資格A']);
        $this->assigned->coaches()->attach($this->coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }

    private function sessionAt(Enrollment $enrollment, Carbon $startedAt): void
    {
        LearningSession::factory()->create([
            'user_id' => $enrollment->user_id,
            'enrollment_id' => $enrollment->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(15),
        ]);
    }

    public function test_last_activity_is_latest_session_start_and_null_without_sessions(): void
    {
        $active = Enrollment::factory()->learning()->for(User::factory()->student()->inProgress()->create(['name' => '活動あり']))->for($this->assigned)->create();
        $this->sessionAt($active, now()->subDays(5));
        $this->sessionAt($active, now()->subDays(1));
        $this->sessionAt($active, now()->subDays(3));
        $idle = Enrollment::factory()->passed()->for(User::factory()->student()->inProgress()->create(['name' => '活動なし']))->for($this->assigned)->create();

        $enrollments = app(FetchCoachDashboardAction::class)($this->coach)->assignedEnrollments->keyBy('id');

        $this->assertTrue(now()->subDays(1)->equalTo(Carbon::parse($enrollments[$active->id]->last_activity_at)));
        $this->assertNull($enrollments[$idle->id]->last_activity_at);

        // 受講生 / 資格は Eager Load 済み、学習セッションは全件読み込みしていない(集約のみ)
        foreach ($enrollments as $enrollment) {
            $this->assertTrue($enrollment->relationLoaded('user'));
            $this->assertTrue($enrollment->relationLoaded('certification'));
            $this->assertFalse($enrollment->relationLoaded('learningSessions'));
        }
    }

    public function test_scope_still_limits_to_assigned_certifications_and_learning_or_passed(): void
    {
        $learning = Enrollment::factory()->learning()->for($this->assigned)->create();
        $passed = Enrollment::factory()->passed()->for($this->assigned)->create();
        Enrollment::factory()->failed()->for($this->assigned)->create();
        Enrollment::factory()->learning()->for(Certification::factory()->published())->create();

        $ids = app(FetchCoachDashboardAction::class)($this->coach)->assignedEnrollments->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$learning->id, $passed->id], $ids);
    }

    public function test_dashboard_renders_student_name_certification_and_last_activity(): void
    {
        $enrollment = Enrollment::factory()->learning()->for(User::factory()->student()->inProgress()->create(['name' => '表示確認さん']))->for($this->assigned)->create();
        $this->sessionAt($enrollment, now()->subDays(2));

        $this->actingAs($this->coach)
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('表示確認さん')
            ->assertSee('担当資格A')
            ->assertSee(now()->subDays(2)->diffForHumans());
    }
}
