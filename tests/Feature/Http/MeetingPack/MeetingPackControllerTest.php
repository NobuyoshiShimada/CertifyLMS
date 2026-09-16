<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Enums\UserRole;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MeetingPackController に関する認可・CRUD・状態遷移・削除ガードを検証する機能テスト。
 */
class MeetingPackControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_index_screen_can_be_rendered_for_admin_with_keyword_and_status_filter(): void
    {
        $admin = $this->admin();
        MeetingPack::factory()->published()->create(['name' => '5回パック']);
        MeetingPack::factory()->draft()->create(['name' => '3回パック']);

        $response = $this->actingAs($admin)->get(route('admin.meeting-packs.index', [
            'keyword' => '5回',
            'status' => MeetingPackStatus::Published->value,
        ]));

        $response->assertStatus(200);
        $response->assertViewIs('meeting-pack.management.index');
        $plans = $response->viewData('plans');
        $this->assertSame(1, $plans->total());
        $this->assertSame('5回パック', $plans->first()->name);
    }

    public function test_index_is_forbidden_for_non_admin(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);

        $response = $this->actingAs($student)->get(route('admin.meeting-packs.index'));

        $response->assertForbidden();
    }

    public function test_create_screen_can_be_rendered_for_admin(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.meeting-packs.create'));

        $response->assertStatus(200);
        $response->assertViewIs('meeting-pack.management.create');
    }

    public function test_store_creates_pack_as_draft_and_redirects_to_show(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post(route('admin.meeting-packs.store'), [
            'name' => 'テストパック',
            'description' => '説明文',
            'meeting_count' => 5,
            'price' => 12000,
            'stripe_price_id' => 'price_test123',
            'sort_order' => 10,
        ]);

        $plan = MeetingPack::where('name', 'テストパック')->first();

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('meeting_packs', [
            'name' => 'テストパック',
            'meeting_count' => 5,
            'price' => 12000,
            'status' => MeetingPackStatus::Draft->value,
            'created_by_user_id' => $admin->id,
        ]);
    }

    public function test_store_validates_required_and_range_fields(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.meeting-packs.store'), [
            'name' => '',
            'meeting_count' => 0,
            'price' => -1,
        ]);

        $response->assertSessionHasErrors(['name', 'meeting_count', 'price']);
        $this->assertDatabaseCount('meeting_packs', 0);
    }

    public function test_store_is_forbidden_for_non_admin(): void
    {
        $coach = User::factory()->create(['role' => UserRole::Coach]);

        $response = $this->actingAs($coach)->post(route('admin.meeting-packs.store'), [
            'name' => 'テストパック',
            'meeting_count' => 5,
            'price' => 12000,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('meeting_packs', 0);
    }

    public function test_show_screen_can_be_rendered_for_admin(): void
    {
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($this->admin())->get(route('admin.meeting-packs.show', $plan));

        $response->assertStatus(200);
        $response->assertViewIs('meeting-pack.management.show');
    }

    public function test_edit_screen_can_be_rendered_for_admin(): void
    {
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($this->admin())->get(route('admin.meeting-packs.edit', $plan));

        $response->assertStatus(200);
        $response->assertViewIs('meeting-pack.management.edit');
    }

    public function test_update_modifies_basic_fields_without_changing_status(): void
    {
        $admin = $this->admin();
        $plan = MeetingPack::factory()->published()->create(['name' => '旧名称']);

        $response = $this->actingAs($admin)->patch(route('admin.meeting-packs.update', $plan), [
            'name' => '新名称',
            'meeting_count' => 8,
            'price' => 20000,
        ]);

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));

        $this->assertDatabaseHas('meeting_packs', [
            'id' => $plan->id,
            'name' => '新名称',
            'meeting_count' => 8,
            'price' => 20000,
            'status' => MeetingPackStatus::Published->value,
            'updated_by_user_id' => $admin->id,
        ]);
    }

    public function test_destroy_deletes_draft_pack(): void
    {
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($this->admin())->delete(route('admin.meeting-packs.destroy', $plan));

        $response->assertRedirect(route('admin.meeting-packs.index'));
        $this->assertDatabaseMissing('meeting_packs', ['id' => $plan->id]);
    }

    public function test_destroy_deletes_archived_pack(): void
    {
        $plan = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($this->admin())->delete(route('admin.meeting-packs.destroy', $plan));

        $response->assertRedirect(route('admin.meeting-packs.index'));
        $this->assertDatabaseMissing('meeting_packs', ['id' => $plan->id]);
    }

    public function test_destroy_rejects_published_pack(): void
    {
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($this->admin())->delete(route('admin.meeting-packs.destroy', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('meeting_packs', ['id' => $plan->id]);
    }

    public function test_publish_transitions_draft_to_published(): void
    {
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($this->admin())->post(route('admin.meeting-packs.publish', $plan));

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));
        $this->assertSame(MeetingPackStatus::Published, $plan->fresh()->status);
    }

    public function test_publish_rejects_non_draft_pack(): void
    {
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($this->admin())->post(route('admin.meeting-packs.publish', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(MeetingPackStatus::Published, $plan->fresh()->status);
    }

    public function test_archive_transitions_published_to_archived(): void
    {
        $plan = MeetingPack::factory()->published()->create();

        $response = $this->actingAs($this->admin())->post(route('admin.meeting-packs.archive', $plan));

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));
        $this->assertSame(MeetingPackStatus::Archived, $plan->fresh()->status);
    }

    public function test_archive_rejects_draft_pack(): void
    {
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($this->admin())->post(route('admin.meeting-packs.archive', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(MeetingPackStatus::Draft, $plan->fresh()->status);
    }

    public function test_unarchive_transitions_archived_to_published(): void
    {
        $plan = MeetingPack::factory()->archived()->create();

        $response = $this->actingAs($this->admin())->post(route('admin.meeting-packs.unarchive', $plan));

        $response->assertRedirect(route('admin.meeting-packs.show', $plan));
        $this->assertSame(MeetingPackStatus::Published, $plan->fresh()->status);
    }

    public function test_unarchive_rejects_draft_pack(): void
    {
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($this->admin())->post(route('admin.meeting-packs.unarchive', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(MeetingPackStatus::Draft, $plan->fresh()->status);
    }

    public function test_transition_actions_are_forbidden_for_non_admin(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $plan = MeetingPack::factory()->draft()->create();

        $this->actingAs($student)->post(route('admin.meeting-packs.publish', $plan))->assertForbidden();
        $this->actingAs($student)->delete(route('admin.meeting-packs.destroy', $plan))->assertForbidden();
    }
}
