<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 修了証 PDF ダウンロード(本人 / 担当コーチ / 管理者の認可、学習中ガード非適用、ファイル欠損 404、添付ファイル名)を検証する。
 */
class CertificateDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $assignedCoach;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $admin = User::factory()->admin()->create();
        $this->student = User::factory()->student()->inProgress()->create();
        $this->assignedCoach = User::factory()->coach()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $certification->coaches()->attach($this->assignedCoach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => $admin->id,
            'assigned_at' => now(),
        ]);
        $enrollment = Enrollment::factory()->for($this->student)->for($certification)->passed()->create();
        $this->certificate = Certificate::factory()->create([
            'user_id' => $this->student->id,
            'enrollment_id' => $enrollment->id,
            'certification_id' => $certification->id,
        ]);
        Storage::disk('private')->put($this->certificate->pdf_path, '%PDF-1.4 certificate');
    }

    private function download(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('certificates.download', $this->certificate));
    }

    public function test_owner_downloads_as_attachment_with_certificate_id_filename(): void
    {
        $response = $this->download($this->student);

        $response->assertOk();
        $response->assertDownload("certificate-{$this->certificate->id}.pdf");
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertSame('%PDF-1.4 certificate', $response->streamedContent());
    }

    public function test_graduated_owner_can_still_download(): void
    {
        $this->student->update(['status' => 'graduated']);

        $this->download($this->student->fresh())->assertOk()->assertDownload();
    }

    public function test_assigned_coach_and_admin_can_download(): void
    {
        $this->download($this->assignedCoach)->assertOk()->assertDownload();
        $this->download(User::factory()->admin()->create())->assertOk()->assertDownload();
    }

    public function test_other_student_and_unassigned_coach_get_403(): void
    {
        $this->download(User::factory()->student()->inProgress()->create())->assertForbidden();
        $this->download(User::factory()->coach()->inProgress()->create())->assertForbidden();
    }

    public function test_missing_pdf_file_returns_404(): void
    {
        Storage::disk('private')->delete($this->certificate->pdf_path);

        $this->download($this->student)->assertNotFound();
    }

    public function test_unknown_certificate_returns_404(): void
    {
        $this->actingAs($this->student)
            ->get(route('certificates.download', ['certificate' => (string) Str::ulid()]))
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('certificates.download', $this->certificate))->assertRedirect(route('login'));
    }
}
