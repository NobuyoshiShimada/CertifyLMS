<?php

declare(strict_types=1);

namespace Tests\Feature\Http\EnrollmentNote;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EnrollmentNoteController に関する担当資格 / 作成者 / 管理者越境の認可、作成者の不変性、一覧の表示制御を検証する機能テスト。
 */
class EnrollmentNoteControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $coach;

    private User $otherCoach;

    private User $admin;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->student = User::factory()->student()->inProgress()->create();
        $this->coach = User::factory()->coach()->create();
        $this->otherCoach = User::factory()->coach()->create();

        $certification = Certification::factory()->published()->create();
        $this->assign($certification, $this->coach);
        $this->assign($certification, $this->otherCoach);

        $this->enrollment = Enrollment::factory()->learning()->for($this->student)->for($certification)->create();
    }

    private function assign(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $this->admin->id,
            'assigned_at' => now(),
        ]);
    }

    private function note(User $author, array $attributes = []): EnrollmentNote
    {
        return EnrollmentNote::factory()->forEnrollment($this->enrollment)->byAuthor($author)->create($attributes);
    }

    public function test_assigned_coach_can_add_note_as_author(): void
    {
        $response = $this->actingAs($this->coach)
            ->post(route('enrollments.notes.store', $this->enrollment), ['body' => '最近 chat の応答が遅い']);

        $response->assertRedirect(route('enrollments.show', $this->enrollment));
        $response->assertSessionHas('success');
        $note = EnrollmentNote::sole();
        $this->assertSame('最近 chat の応答が遅い', $note->body);
        $this->assertSame($this->coach->id, $note->author_user_id);
        $this->assertSame($this->enrollment->id, $note->enrollment_id);
    }

    public function test_admin_can_add_note_to_any_enrollment_as_author(): void
    {
        $unassigned = Enrollment::factory()->learning()->create();

        $this->actingAs($this->admin)
            ->post(route('enrollments.notes.store', $unassigned), ['body' => '運営観察'])
            ->assertRedirect(route('enrollments.show', $unassigned));

        $this->assertDatabaseHas('enrollment_notes', [
            'enrollment_id' => $unassigned->id,
            'author_user_id' => $this->admin->id,
        ]);
    }

    public function test_note_can_be_added_to_passed_or_failed_enrollment(): void
    {
        foreach (['passed', 'failed'] as $state) {
            $enrollment = Enrollment::factory()->{$state}()
                ->for(User::factory()->student())
                ->for($this->enrollment->certification)
                ->create();

            $this->actingAs($this->coach)
                ->post(route('enrollments.notes.store', $enrollment), ['body' => '申し送り'])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('enrollment_notes', 2);
    }

    /**
     * @dataProvider invalidBodyProvider
     */
    public function test_store_and_update_reject_invalid_body(?string $body): void
    {
        $note = $this->note($this->coach, ['body' => '元の本文']);

        $this->actingAs($this->coach)
            ->post(route('enrollments.notes.store', $this->enrollment), ['body' => $body])
            ->assertSessionHasErrors('body');
        $this->actingAs($this->coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => $body])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('enrollment_notes', 1);
        $this->assertSame('元の本文', $note->fresh()->body);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidBodyProvider(): array
    {
        return ['空' => [''], '未送信' => [null], '2001 文字' => [str_repeat('あ', 2001)]];
    }

    public function test_body_of_2000_chars_is_accepted(): void
    {
        $this->actingAs($this->coach)
            ->post(route('enrollments.notes.store', $this->enrollment), ['body' => str_repeat('あ', 2000)])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_author_coach_can_edit_and_update_own_note(): void
    {
        $note = $this->note($this->coach);

        $this->actingAs($this->coach)
            ->get(route('enrollment-notes.edit', $note))
            ->assertOk()
            ->assertViewIs('enrollment-note.edit');

        $this->actingAs($this->coach)
            ->patch(route('enrollment-notes.update', $note), ['body' => '更新後'])
            ->assertRedirect(route('enrollments.show', $this->enrollment))
            ->assertSessionHas('success');

        $this->assertSame('更新後', $note->fresh()->body);
    }

    public function test_admin_update_keeps_original_author(): void
    {
        $note = $this->note($this->coach);

        $this->actingAs($this->admin)->patch(route('enrollment-notes.update', $note), [
            'body' => '管理者が是正',
            'author_user_id' => $this->admin->id,
        ])->assertRedirect(route('enrollments.show', $this->enrollment));

        $note->refresh();
        $this->assertSame('管理者が是正', $note->body);
        $this->assertSame($this->coach->id, $note->author_user_id);
    }

    public function test_author_coach_and_admin_can_delete_note_physically(): void
    {
        $own = $this->note($this->coach);
        $others = $this->note($this->otherCoach);

        $this->actingAs($this->coach)
            ->delete(route('enrollment-notes.destroy', $own))
            ->assertRedirect(route('enrollments.show', $this->enrollment));
        $this->actingAs($this->admin)
            ->delete(route('enrollment-notes.destroy', $others))
            ->assertRedirect(route('enrollments.show', $this->enrollment));

        $this->assertDatabaseCount('enrollment_notes', 0);
    }

    public function test_coach_cannot_edit_update_or_delete_other_coach_note(): void
    {
        $note = $this->note($this->otherCoach, ['body' => '他コーチの本文']);

        $this->actingAs($this->coach);
        $this->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->patch(route('enrollment-notes.update', $note), ['body' => '改ざん'])->assertForbidden();
        $this->delete(route('enrollment-notes.destroy', $note))->assertForbidden();

        $this->assertSame('他コーチの本文', $note->fresh()->body);
    }

    public function test_unassigned_coach_cannot_add_or_view_notes(): void
    {
        $outsider = User::factory()->coach()->create();
        $this->note($this->coach, ['body' => '担当外に見せない']);

        $this->actingAs($outsider)
            ->post(route('enrollments.notes.store', $this->enrollment), ['body' => '越境'])
            ->assertForbidden();
        $this->actingAs($outsider)
            ->get(route('enrollments.show', $this->enrollment))
            ->assertForbidden();

        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_student_is_forbidden_for_all_note_routes(): void
    {
        $note = $this->note($this->coach);

        $this->actingAs($this->student);
        $this->post(route('enrollments.notes.store', $this->enrollment), ['body' => '本人'])->assertForbidden();
        $this->get(route('enrollment-notes.edit', $note))->assertForbidden();
        $this->patch(route('enrollment-notes.update', $note), ['body' => '本人'])->assertForbidden();
        $this->delete(route('enrollment-notes.destroy', $note))->assertForbidden();

        $this->assertDatabaseCount('enrollment_notes', 1);
    }

    public function test_student_does_not_see_note_section_on_own_enrollment(): void
    {
        $this->note($this->coach, ['body' => '受講生には秘密の観察']);

        $this->actingAs($this->student)
            ->get(route('enrollments.show', $this->enrollment))
            ->assertOk()
            ->assertDontSee('コーチメモ')
            ->assertDontSee('受講生には秘密の観察');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $note = $this->note($this->coach);

        $this->post(route('enrollments.notes.store', $this->enrollment), ['body' => 'x'])->assertRedirect(route('login'));
        $this->get(route('enrollment-notes.edit', $note))->assertRedirect(route('login'));
    }

    public function test_coach_sees_notes_newest_first_with_buttons_only_on_own_notes(): void
    {
        $this->travelTo(Carbon::parse('2026-09-24 12:00:00'));
        $old = $this->note($this->coach, ['body' => '古い自分のメモ', 'created_at' => now()->subDays(2)]);
        $middle = $this->note($this->otherCoach, ['body' => '他コーチのメモ', 'created_at' => now()->subDay()]);
        $new = $this->note($this->admin, ['body' => '新しい管理者メモ', 'created_at' => now()]);

        $response = $this->actingAs($this->coach)->get(route('enrollments.show', $this->enrollment));

        $response->assertOk();
        $response->assertSeeInOrder(['新しい管理者メモ', '他コーチのメモ', '古い自分のメモ']);
        $response->assertSee(route('enrollment-notes.edit', $old), false);
        $response->assertSee(route('enrollment-notes.destroy', $old), false);
        $response->assertDontSee(route('enrollment-notes.edit', $middle), false);
        $response->assertDontSee(route('enrollment-notes.destroy', $middle), false);
        $response->assertDontSee(route('enrollment-notes.edit', $new), false);
        $response->assertSee(route('enrollments.notes.store', $this->enrollment), false);
    }

    public function test_admin_sees_buttons_on_all_notes(): void
    {
        $coachNote = $this->note($this->coach);
        $otherNote = $this->note($this->otherCoach);

        $response = $this->actingAs($this->admin)->get(route('enrollments.show', $this->enrollment));

        foreach ([$coachNote, $otherNote] as $note) {
            $response->assertSee(route('enrollment-notes.edit', $note), false);
            $response->assertSee(route('enrollment-notes.destroy', $note), false);
        }
    }

    public function test_notes_of_soft_deleted_enrollment_are_excluded_from_list(): void
    {
        $note = $this->note($this->coach, ['body' => '論理削除された受講登録のメモ']);

        $this->enrollment->delete();

        $this->assertDatabaseHas('enrollment_notes', ['id' => $note->id]);
        $this->assertCount(0, $this->enrollment->notes()->get());
        $this->actingAs($this->admin)
            ->get(route('enrollments.show', $this->enrollment))
            ->assertOk()
            ->assertDontSee('論理削除された受講登録のメモ');
    }

    public function test_physical_deletion_of_enrollment_cascades_to_notes(): void
    {
        $note = $this->note($this->coach);

        $this->enrollment->forceDelete();

        $this->assertDatabaseMissing('enrollment_notes', ['id' => $note->id]);
    }

    public function test_missing_note_returns_404(): void
    {
        $this->actingAs($this->admin)
            ->get(route('enrollment-notes.edit', ['note' => (string) Str::ulid()]))
            ->assertNotFound();
    }
}
