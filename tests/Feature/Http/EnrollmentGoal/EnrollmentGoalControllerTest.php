<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentGoal;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnrollmentGoalController に関する本人限定の CRUD・達成マーク / 解除の冪等性・並び順・閲覧時の表示制御を検証する機能テスト。
 */
class EnrollmentGoalControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->inProgress()->create();
        $this->enrollment = Enrollment::factory()->learning()->for($this->student)->create();
    }

    private function goal(array $attributes = []): EnrollmentGoal
    {
        return EnrollmentGoal::factory()->forEnrollment($this->enrollment)->create($attributes);
    }

    private function assignedCoach(): User
    {
        $coach = User::factory()->coach()->create();
        $this->enrollment->certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);

        return $coach;
    }

    public function test_student_can_add_goal_with_title_only(): void
    {
        $response = $this->actingAs($this->student)
            ->post(route('enrollments.goals.store', $this->enrollment), ['title' => '過去問を解く']);

        $response->assertRedirect(route('enrollments.show', $this->enrollment));
        $response->assertSessionHas('success');
        $goal = $this->enrollment->goals()->sole();
        $this->assertSame('過去問を解く', $goal->title);
        $this->assertNull($goal->description);
        $this->assertNull($goal->target_date);
        $this->assertNull($goal->achieved_at);
    }

    public function test_student_can_add_goal_with_past_target_date(): void
    {
        $this->actingAs($this->student)->post(route('enrollments.goals.store', $this->enrollment), [
            'title' => '第 1 章を読む',
            'description' => '詳細',
            'target_date' => now()->subDays(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(now()->subDays(3)->toDateString(), $this->enrollment->goals()->sole()->target_date->toDateString());
    }

    /**
     * @dataProvider invalidInputProvider
     *
     * @param array<string, mixed> $input
     */
    public function test_store_rejects_invalid_input(array $input, string $errorField): void
    {
        $this->actingAs($this->student)
            ->post(route('enrollments.goals.store', $this->enrollment), $input)
            ->assertSessionHasErrors($errorField);

        $this->assertDatabaseCount('enrollment_goals', 0);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputProvider(): array
    {
        return [
            'タイトル未入力' => [['title' => ''], 'title'],
            'タイトル 101 文字' => [['title' => str_repeat('あ', 101)], 'title'],
            '詳細 1001 文字' => [['title' => '目標', 'description' => str_repeat('あ', 1001)], 'description'],
            '目標期日が日付でない' => [['title' => '目標', 'target_date' => 'not-a-date'], 'target_date'],
        ];
    }

    public function test_store_accepts_boundary_lengths(): void
    {
        $this->actingAs($this->student)->post(route('enrollments.goals.store', $this->enrollment), [
            'title' => str_repeat('あ', 100),
            'description' => str_repeat('あ', 1000),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('enrollment_goals', 1);
    }

    public function test_student_can_open_edit_page(): void
    {
        $goal = $this->goal();

        $this->actingAs($this->student)
            ->get(route('enrollment-goals.edit', $goal))
            ->assertOk()
            ->assertViewIs('enrollment-goal.edit');
    }

    public function test_update_changes_content_but_keeps_achieved_state(): void
    {
        $achievedAt = Carbon::parse('2026-09-01 10:00:00');
        $goal = $this->goal(['achieved_at' => $achievedAt]);

        $response = $this->actingAs($this->student)->patch(route('enrollment-goals.update', $goal), [
            'title' => '更新後',
            'description' => null,
            'target_date' => null,
            'achieved_at' => null,
        ]);

        $response->assertRedirect(route('enrollments.show', $this->enrollment));
        $goal->refresh();
        $this->assertSame('更新後', $goal->title);
        $this->assertNull($goal->target_date);
        $this->assertTrue($achievedAt->equalTo($goal->achieved_at));
    }

    public function test_update_does_not_mark_unachieved_goal_as_achieved(): void
    {
        $goal = $this->goal();

        $this->actingAs($this->student)->patch(route('enrollment-goals.update', $goal), [
            'title' => '更新後',
            'achieved_at' => now()->toDateTimeString(),
        ]);

        $this->assertNull($goal->fresh()->achieved_at);
    }

    public function test_student_can_delete_goal_physically(): void
    {
        $goal = $this->goal();

        $this->actingAs($this->student)
            ->delete(route('enrollment-goals.destroy', $goal))
            ->assertRedirect(route('enrollments.show', $this->enrollment));

        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_mark_achieved_sets_achieved_at_and_is_idempotent(): void
    {
        $goal = $this->goal(['achieved_at' => Carbon::parse('2026-09-20 10:00:00')]);
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));

        $this->actingAs($this->student)
            ->post(route('enrollment-goals.markAchieved', $goal))
            ->assertRedirect(route('enrollments.show', $this->enrollment))
            ->assertSessionHas('success');

        $this->assertTrue(now()->equalTo($goal->fresh()->achieved_at));
    }

    public function test_mark_achieved_on_unachieved_goal(): void
    {
        $goal = $this->goal();

        $this->actingAs($this->student)->post(route('enrollment-goals.markAchieved', $goal));

        $this->assertNotNull($goal->fresh()->achieved_at);
    }

    public function test_unmark_achieved_clears_achieved_at_and_is_idempotent(): void
    {
        $achieved = $this->goal(['achieved_at' => now()]);
        $notAchieved = $this->goal();

        foreach ([$achieved, $notAchieved] as $goal) {
            $this->actingAs($this->student)
                ->delete(route('enrollment-goals.unmarkAchieved', $goal))
                ->assertRedirect(route('enrollments.show', $this->enrollment))
                ->assertSessionHas('success');

            $this->assertNull($goal->fresh()->achieved_at);
        }
    }

    public function test_goals_are_ordered_unachieved_first_then_target_date_with_undated_last(): void
    {
        $this->travelTo(Carbon::parse('2026-09-23 12:00:00'));
        $achievedSoon = $this->goal(['target_date' => '2026-09-24', 'achieved_at' => now()]);
        $undated = $this->goal(['target_date' => null]);
        $later = $this->goal(['target_date' => '2026-10-30']);
        $soonOlder = $this->goal(['target_date' => '2026-10-01', 'created_at' => now()->subDay()]);
        $soonNewer = $this->goal(['target_date' => '2026-10-01', 'created_at' => now()]);

        $this->assertSame(
            [$soonNewer->id, $soonOlder->id, $later->id, $undated->id, $achievedSoon->id],
            $this->enrollment->goals()->pluck('id')->all(),
        );
    }

    public function test_deleting_enrollment_physically_cascades_to_goals(): void
    {
        $goal = $this->goal();

        $this->enrollment->forceDelete();

        $this->assertDatabaseMissing('enrollment_goals', ['id' => $goal->id]);
    }

    public function test_owner_sees_operation_buttons_on_enrollment_show(): void
    {
        $goal = $this->goal(['title' => '本人の目標']);

        $this->actingAs($this->student)
            ->get(route('enrollments.show', $this->enrollment))
            ->assertOk()
            ->assertSee('本人の目標')
            ->assertSee(route('enrollments.goals.store', $this->enrollment), false)
            ->assertSee(route('enrollment-goals.edit', $goal), false)
            ->assertSee(route('enrollment-goals.markAchieved', $goal), false);
    }

    public function test_assigned_coach_and_admin_can_view_goals_without_operation_buttons(): void
    {
        $goal = $this->goal(['title' => '閲覧される目標']);

        foreach ([$this->assignedCoach(), User::factory()->admin()->create()] as $viewer) {
            $this->actingAs($viewer)
                ->get(route('enrollments.show', $this->enrollment))
                ->assertOk()
                ->assertSee('閲覧される目標')
                ->assertDontSee(route('enrollments.goals.store', $this->enrollment), false)
                ->assertDontSee(route('enrollment-goals.edit', $goal), false)
                ->assertDontSee(route('enrollment-goals.destroy', $goal), false)
                ->assertDontSee(route('enrollment-goals.markAchieved', $goal), false);
        }
    }

    /**
     * @dataProvider nonOwnerProvider
     */
    public function test_non_owner_is_forbidden_for_all_operations(string $who): void
    {
        $actor = match ($who) {
            'other_student' => User::factory()->student()->inProgress()->create(),
            'assigned_coach' => $this->assignedCoach(),
            'admin' => User::factory()->admin()->create(),
        };
        $goal = $this->goal(['achieved_at' => null]);
        $payload = ['title' => '改ざん'];

        $this->actingAs($actor);
        $this->post(route('enrollments.goals.store', $this->enrollment), $payload)->assertForbidden();
        $this->get(route('enrollment-goals.edit', $goal))->assertForbidden();
        $this->patch(route('enrollment-goals.update', $goal), $payload)->assertForbidden();
        $this->delete(route('enrollment-goals.destroy', $goal))->assertForbidden();
        $this->post(route('enrollment-goals.markAchieved', $goal))->assertForbidden();
        $this->delete(route('enrollment-goals.unmarkAchieved', $goal))->assertForbidden();

        $goal->refresh();
        $this->assertNotSame('改ざん', $goal->title);
        $this->assertNull($goal->achieved_at);
        $this->assertDatabaseCount('enrollment_goals', 1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonOwnerProvider(): array
    {
        return ['他受講生' => ['other_student'], '担当コーチ' => ['assigned_coach'], '管理者' => ['admin']];
    }

    public function test_other_student_cannot_view_enrollment_show(): void
    {
        $other = User::factory()->student()->inProgress()->create();

        $this->actingAs($other)->get(route('enrollments.show', $this->enrollment))->assertForbidden();
    }

    public function test_missing_goal_returns_404(): void
    {
        $this->actingAs($this->student)
            ->post(route('enrollment-goals.markAchieved', ['goal' => (string) Str::ulid()]))
            ->assertNotFound();
    }

    public function test_goal_state_of_enrollment_does_not_block_owner_operations(): void
    {
        $passed = Enrollment::factory()->passed()->for($this->student)
            ->for(Certification::factory()->published())->create();

        $this->actingAs($this->student)
            ->post(route('enrollments.goals.store', $passed), ['title' => '合格後も記録'])
            ->assertRedirect(route('enrollments.show', $passed));

        $this->assertDatabaseHas('enrollment_goals', ['enrollment_id' => $passed->id, 'title' => '合格後も記録']);
    }
}
