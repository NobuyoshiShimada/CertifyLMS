<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserPlanLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PlanController に関する認可・CRUD・状態遷移・削除ガード(下書き AND 受講者 0 名)を検証する機能テスト。
 */
class PlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '3 ヶ月プラン 12 回',
            'description' => '説明文',
            'duration_days' => 90,
            'default_meeting_quota' => 12,
            'sort_order' => 5,
        ], $overrides);
    }

    public function test_index_screen_can_be_rendered_for_admin_with_keyword_and_status_filter(): void
    {
        Plan::factory()->published()->create(['name' => '3 ヶ月プラン']);
        Plan::factory()->draft()->create(['name' => '3 ヶ月プラン 下書き']);
        Plan::factory()->published()->create(['name' => '12 ヶ月プラン']);

        $response = $this->actingAs($this->admin())->get(route('admin.plans.index', [
            'keyword' => '3 ヶ月',
            'status' => PlanStatus::Published->value,
        ]));

        $response->assertOk();
        $response->assertViewIs('plan.management.index');
        $plans = $response->viewData('plans');
        $this->assertSame(1, $plans->total());
        $this->assertSame('3 ヶ月プラン', $plans->first()->name);
    }

    public function test_index_orders_by_status_priority_then_sort_order(): void
    {
        $archived = Plan::factory()->archived()->create(['sort_order' => 0]);
        $draft = Plan::factory()->draft()->create(['sort_order' => 0]);
        $publishedLater = Plan::factory()->published()->create(['sort_order' => 10]);
        $publishedFirst = Plan::factory()->published()->create(['sort_order' => 1]);

        $response = $this->actingAs($this->admin())->get(route('admin.plans.index'));

        $this->assertSame(
            [$publishedFirst->id, $publishedLater->id, $draft->id, $archived->id],
            $response->viewData('plans')->pluck('id')->all(),
        );
    }

    public function test_index_shows_user_count_without_n_plus_one(): void
    {
        $admin = $this->admin();
        foreach (range(1, 3) as $i) {
            $plan = Plan::factory()->published()->create();
            User::factory()->student()->inProgress()->withPlan($plan)->count($i)->create();
        }

        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get(route('admin.plans.index'));
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $response->assertOk();
        $this->assertEqualsCanonicalizing([1, 2, 3], $response->viewData('plans')->pluck('users_count')->all());
        // 受講者数は一覧取得クエリに集約され、行ごとの count クエリは発行されない
        $this->assertSame(0, $queries->filter(fn ($q) => str_starts_with($q, 'select count(*) as aggregate from `users`'))->count());
    }

    public function test_create_screen_can_be_rendered_for_admin(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.plans.create'))
            ->assertOk()
            ->assertViewIs('plan.management.create');
    }

    public function test_store_creates_plan_as_draft_and_records_admin(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.plans.store'), $this->validPayload());

        $plan = Plan::where('name', '3 ヶ月プラン 12 回')->firstOrFail();
        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('success');
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame(90, $plan->duration_days);
        $this->assertSame(12, $plan->default_meeting_quota);
        $this->assertSame($admin->id, $plan->created_by_user_id);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_store_ignores_status_input_and_defaults_sort_order(): void
    {
        $this->actingAs($this->admin())->post(route('admin.plans.store'), $this->validPayload([
            'status' => PlanStatus::Published->value,
            'sort_order' => null,
        ]));

        $plan = Plan::where('name', '3 ヶ月プラン 12 回')->firstOrFail();
        $this->assertSame(PlanStatus::Draft, $plan->status);
        $this->assertSame(0, $plan->sort_order);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.plans.store'), [])
            ->assertSessionHasErrors(['name', 'duration_days', 'default_meeting_quota'])
            ->assertSessionDoesntHaveErrors(['description', 'sort_order']);
    }

    /**
     * @dataProvider invalidBoundaryProvider
     */
    public function test_store_rejects_out_of_range_values(string $field, mixed $value): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.plans.store'), $this->validPayload([$field => $value]))
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('plans', 0);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidBoundaryProvider(): array
    {
        return [
            'name 101 文字' => ['name', str_repeat('あ', 101)],
            'description 2001 文字' => ['description', str_repeat('あ', 2001)],
            'duration_days 0' => ['duration_days', 0],
            'duration_days 3651' => ['duration_days', 3651],
            'default_meeting_quota -1' => ['default_meeting_quota', -1],
            'default_meeting_quota 1001' => ['default_meeting_quota', 1001],
            'sort_order -1' => ['sort_order', -1],
            'duration_days 非整数' => ['duration_days', '1.5'],
        ];
    }

    public function test_store_accepts_boundary_values(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.plans.store'), $this->validPayload([
                'name' => str_repeat('あ', 100),
                'description' => str_repeat('あ', 2000),
                'duration_days' => 3650,
                'default_meeting_quota' => 0,
                'sort_order' => 0,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('plans', 1);
    }

    public function test_show_screen_displays_users_and_meta(): void
    {
        $plan = Plan::factory()->published()->create();
        $student = User::factory()->student()->inProgress()->withPlan($plan)->create();

        $response = $this->actingAs($this->admin())->get(route('admin.plans.show', $plan));

        $response->assertOk();
        $response->assertViewIs('plan.management.show');
        $response->assertSee($student->email);
        $response->assertSee($plan->createdBy->name);
    }

    public function test_edit_screen_can_be_rendered_for_admin(): void
    {
        $plan = Plan::factory()->published()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.plans.edit', $plan))
            ->assertOk()
            ->assertViewIs('plan.management.edit');
    }

    public function test_update_modifies_basic_fields_of_published_plan_without_changing_status(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->published()->create();

        $response = $this->actingAs($admin)->put(route('admin.plans.update', $plan), $this->validPayload([
            'name' => '改定後プラン',
            'duration_days' => 180,
            'status' => PlanStatus::Archived->value,
        ]));

        $response->assertRedirect(route('admin.plans.show', $plan));
        $plan->refresh();
        $this->assertSame('改定後プラン', $plan->name);
        $this->assertSame(180, $plan->duration_days);
        $this->assertSame(PlanStatus::Published, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_update_does_not_affect_users_already_on_plan(): void
    {
        $plan = Plan::factory()->published()->withDurationDays(30)->create(['default_meeting_quota' => 4]);
        $student = User::factory()->student()->inProgress()->withPlan($plan)->create();
        $before = $student->only(['plan_expires_at', 'max_meetings']);

        $this->actingAs($this->admin())->put(route('admin.plans.update', $plan), $this->validPayload([
            'duration_days' => 365,
            'default_meeting_quota' => 24,
        ]));

        $this->assertEquals($before, $student->fresh()->only(['plan_expires_at', 'max_meetings']));
    }

    public function test_destroy_deletes_draft_plan_without_users(): void
    {
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($this->admin())->delete(route('admin.plans.destroy', $plan));

        $response->assertRedirect(route('admin.plans.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('plans', ['id' => $plan->id]);
    }

    /**
     * @dataProvider nonDraftStatusProvider
     */
    public function test_destroy_rejects_non_draft_plan(string $state): void
    {
        $plan = Plan::factory()->{$state}()->create();

        $response = $this->actingAs($this->admin())
            ->from(route('admin.plans.show', $plan))
            ->delete(route('admin.plans.destroy', $plan));

        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('error', '下書き状態のプランのみ削除できます。先に下書きに戻すか、アーカイブを利用してください。');
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDraftStatusProvider(): array
    {
        return ['公開中' => ['published'], 'アーカイブ' => ['archived']];
    }

    public function test_destroy_rejects_draft_plan_with_users(): void
    {
        $plan = Plan::factory()->draft()->create();
        User::factory()->student()->inProgress()->withPlan($plan)->create();

        $response = $this->actingAs($this->admin())
            ->from(route('admin.plans.show', $plan))
            ->delete(route('admin.plans.destroy', $plan));

        $response->assertRedirect(route('admin.plans.show', $plan));
        $response->assertSessionHas('error', 'このプランは受講者が紐づいているため削除できません。');
        $this->assertDatabaseHas('plans', ['id' => $plan->id]);
    }

    public function test_destroy_rejects_draft_plan_referenced_by_withdrawn_user_or_history(): void
    {
        $admin = $this->admin();
        $withdrawnPlan = Plan::factory()->draft()->create();
        User::factory()->student()->withPlan($withdrawnPlan)->create()->delete();
        $historyPlan = Plan::factory()->draft()->create();
        UserPlanLog::factory()->create(['plan_id' => $historyPlan->id]);

        foreach ([$withdrawnPlan, $historyPlan] as $plan) {
            $this->actingAs($admin)
                ->delete(route('admin.plans.destroy', $plan))
                ->assertSessionHas('error', 'このプランは受講者が紐づいているため削除できません。');
            $this->assertDatabaseHas('plans', ['id' => $plan->id]);
        }
    }

    public function test_destroy_returns_409_for_json_request(): void
    {
        $plan = Plan::factory()->published()->create();

        $this->actingAs($this->admin())
            ->deleteJson(route('admin.plans.destroy', $plan))
            ->assertStatus(409);
    }

    public function test_publish_transitions_draft_to_published(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->draft()->create();

        $response = $this->actingAs($admin)->post(route('admin.plans.publish', $plan));

        $response->assertRedirect(route('admin.plans.show', $plan));
        $plan->refresh();
        $this->assertSame(PlanStatus::Published, $plan->status);
        $this->assertSame($admin->id, $plan->updated_by_user_id);
    }

    public function test_archive_transitions_published_to_archived(): void
    {
        $plan = Plan::factory()->published()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.plans.archive', $plan))
            ->assertRedirect(route('admin.plans.show', $plan));

        $this->assertSame(PlanStatus::Archived, $plan->fresh()->status);
    }

    public function test_unarchive_transitions_archived_to_draft(): void
    {
        $plan = Plan::factory()->archived()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.plans.unarchive', $plan))
            ->assertRedirect(route('admin.plans.show', $plan));

        $this->assertSame(PlanStatus::Draft, $plan->fresh()->status);
    }

    /**
     * @dataProvider invalidTransitionProvider
     */
    public function test_invalid_transition_is_rejected_with_409(string $state, string $route, PlanStatus $expected): void
    {
        $plan = Plan::factory()->{$state}()->create();

        $this->actingAs($this->admin())
            ->postJson(route($route, $plan))
            ->assertStatus(409);

        $this->actingAs($this->admin())
            ->from(route('admin.plans.show', $plan))
            ->post(route($route, $plan))
            ->assertRedirect(route('admin.plans.show', $plan))
            ->assertSessionHas('error');

        $this->assertSame($expected, $plan->fresh()->status);
    }

    /**
     * @return array<string, array{string, string, PlanStatus}>
     */
    public static function invalidTransitionProvider(): array
    {
        return [
            '公開中 → 公開' => ['published', 'admin.plans.publish', PlanStatus::Published],
            'アーカイブ → 公開' => ['archived', 'admin.plans.publish', PlanStatus::Archived],
            '下書き → アーカイブ' => ['draft', 'admin.plans.archive', PlanStatus::Draft],
            'アーカイブ → アーカイブ' => ['archived', 'admin.plans.archive', PlanStatus::Archived],
            '下書き → 下書きへ戻す' => ['draft', 'admin.plans.unarchive', PlanStatus::Draft],
            '公開中 → 下書きへ戻す(直接戻し禁止)' => ['published', 'admin.plans.unarchive', PlanStatus::Published],
        ];
    }

    /**
     * @dataProvider nonAdminProvider
     */
    public function test_all_actions_are_forbidden_for_non_admin(string $role): void
    {
        $user = User::factory()->{$role}()->create();
        $plan = Plan::factory()->draft()->create();

        $this->actingAs($user);
        $this->get(route('admin.plans.index'))->assertForbidden();
        $this->get(route('admin.plans.create'))->assertForbidden();
        $this->post(route('admin.plans.store'), $this->validPayload())->assertForbidden();
        $this->get(route('admin.plans.show', $plan))->assertForbidden();
        $this->get(route('admin.plans.edit', $plan))->assertForbidden();
        $this->put(route('admin.plans.update', $plan), $this->validPayload())->assertForbidden();
        $this->delete(route('admin.plans.destroy', $plan))->assertForbidden();
        $this->post(route('admin.plans.publish', $plan))->assertForbidden();
        $this->post(route('admin.plans.archive', $plan))->assertForbidden();
        $this->post(route('admin.plans.unarchive', $plan))->assertForbidden();

        $this->assertDatabaseCount('plans', 1);
        $this->assertSame(PlanStatus::Draft, $plan->fresh()->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminProvider(): array
    {
        return ['受講生' => ['student'], 'コーチ' => ['coach']];
    }
}
