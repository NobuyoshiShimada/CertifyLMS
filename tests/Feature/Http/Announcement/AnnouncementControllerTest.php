<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AnnouncementController に関する認可・配信対象解決・配信実績記録を検証する機能テスト。
 */
class AnnouncementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_screen_can_be_rendered_for_admin(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Announcement::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('admin.announcements.index'));

        $response->assertStatus(200);
        $response->assertViewIs('announcement.management.index');
        $response->assertViewHas('announcements');
    }

    public function test_index_is_forbidden_for_non_admin(): void
    {
        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);

        $response = $this->actingAs($student)->get(route('admin.announcements.index'));

        $response->assertForbidden();
    }

    public function test_create_screen_can_be_rendered_for_admin_with_certifications_and_students(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Certification::factory()->create(['name' => 'テスト資格']);
        User::factory()->create(['role' => UserRole::Student, 'name' => 'テスト受講生']);

        $response = $this->actingAs($admin)->get(route('admin.announcements.create'));

        $response->assertStatus(200);
        $response->assertViewIs('announcement.management.create');
        $response->assertViewHasAll(['certifications', 'students']);
    }

    public function test_create_is_forbidden_for_non_admin(): void
    {
        /** @var User $coach */
        $coach = User::factory()->create(['role' => UserRole::Coach]);

        $response = $this->actingAs($coach)->get(route('admin.announcements.create'));

        $response->assertForbidden();
    }

    public function test_store_with_all_students_dispatches_to_every_active_student_only(): void
    {
        Notification::fake();

        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $activeStudentA = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::InProgress]);
        $activeStudentB = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::Graduated]);
        $withdrawnStudent = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::Withdrawn]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '全受講生向けお知らせ',
            'body' => 'メンテナンスのお知らせです。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $announcement = Announcement::first();

        $response->assertRedirect(route('admin.announcements.show', $announcement));
        $response->assertSessionHas('success', 'お知らせを配信しました。');

        $this->assertDatabaseHas('announcements', [
            'title' => '全受講生向けお知らせ',
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'dispatched_count' => 2,
            'created_by' => $admin->id,
        ]);

        Notification::assertSentTo([$activeStudentA, $activeStudentB], AdminAnnouncementNotification::class);
        Notification::assertNotSentTo($withdrawnStudent, AdminAnnouncementNotification::class);
    }

    public function test_store_with_certification_dispatches_only_to_students_enrolled_in_that_certification(): void
    {
        Notification::fake();

        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $certificationA = Certification::factory()->published()->create();
        $certificationB = Certification::factory()->published()->create();

        $studentInA = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::InProgress]);
        Enrollment::factory()->for($studentInA)->for($certificationA)->learning()->create();

        $studentInB = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::InProgress]);
        Enrollment::factory()->for($studentInB)->for($certificationB)->learning()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '資格限定のお知らせ',
            'body' => '教材が更新されました。',
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certificationA->id,
        ]);

        $announcement = Announcement::first();
        $response->assertRedirect(route('admin.announcements.show', $announcement));

        $this->assertDatabaseHas('announcements', [
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certificationA->id,
            'dispatched_count' => 1,
        ]);

        Notification::assertSentTo($studentInA, AdminAnnouncementNotification::class);
        Notification::assertNotSentTo($studentInB, AdminAnnouncementNotification::class);
    }

    public function test_store_with_user_target_dispatches_only_to_that_user(): void
    {
        Notification::fake();

        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $targetStudent = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::InProgress]);
        $otherStudent = User::factory()->create(['role' => UserRole::Student, 'status' => UserStatus::InProgress]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '個別のお知らせ',
            'body' => '学習状況のご確認です。',
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $targetStudent->id,
        ]);

        $announcement = Announcement::first();
        $response->assertRedirect(route('admin.announcements.show', $announcement));

        $this->assertDatabaseHas('announcements', [
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $targetStudent->id,
            'dispatched_count' => 1,
        ]);

        Notification::assertSentTo($targetStudent, AdminAnnouncementNotification::class);
        Notification::assertNotSentTo($otherStudent, AdminAnnouncementNotification::class);
    }

    public function test_store_rejects_coach_as_user_target(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $coach = User::factory()->create(['role' => UserRole::Coach]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => '個別のお知らせ',
            'body' => '本文です。',
            'target_type' => AnnouncementTargetType::User->value,
            'target_user_id' => $coach->id,
        ]);

        $response->assertSessionHasErrors('target_user_id');
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_store_requires_certification_id_when_target_type_is_certification(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文です。',
            'target_type' => AnnouncementTargetType::Certification->value,
        ]);

        $response->assertSessionHasErrors('target_certification_id');
    }

    public function test_store_rejects_non_published_certification_as_target(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $draftCertification = Certification::factory()->draft()->create();

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文です。',
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $draftCertification->id,
        ]);

        $response->assertSessionHasErrors('target_certification_id');
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_create_screen_lists_published_certifications_deduplicated_by_name(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Certification::factory()->draft()->create(['name' => '重複資格']);
        Certification::factory()->published()->create(['name' => '重複資格']);
        Certification::factory()->published()->create(['name' => '重複資格']);
        Certification::factory()->published()->create(['name' => '別の資格']);

        $response = $this->actingAs($admin)->get(route('admin.announcements.create'));

        $response->assertStatus(200);
        $certifications = $response->viewData('certifications');

        $this->assertSame(['別の資格', '重複資格'], $certifications->pluck('name')->sort()->values()->all());
    }

    public function test_store_is_forbidden_for_non_admin(): void
    {
        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);

        $response = $this->actingAs($student)->post(route('admin.announcements.store'), [
            'title' => 'タイトル',
            'body' => '本文です。',
            'target_type' => AnnouncementTargetType::AllStudents->value,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_show_screen_can_be_rendered_for_admin(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $announcement = Announcement::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('admin.announcements.show', $announcement));

        $response->assertStatus(200);
        $response->assertViewIs('announcement.management.show');
        $response->assertViewHas('announcement');
    }

    public function test_show_is_forbidden_for_non_admin(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $announcement = Announcement::factory()->create(['created_by' => $admin->id]);

        /** @var User $coach */
        $coach = User::factory()->create(['role' => UserRole::Coach]);

        $response = $this->actingAs($coach)->get(route('admin.announcements.show', $announcement));

        $response->assertForbidden();
    }
}
