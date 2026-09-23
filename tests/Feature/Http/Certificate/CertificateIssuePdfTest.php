<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\Services\Certificate\CertificatePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * 修了証受領(修了証発行)と同じ処理単位での PDF 生成・保存と、生成失敗時の巻き戻し(レコード / 修了遷移 / 部分ファイル)を検証する。
 * PDF の描画は高速化のためスタブし、実際の描画内容は CertificatePdfRendererTest で検証する。
 */
class CertificateIssuePdfTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');
        $this->student = User::factory()->student()->inProgress()->create();
        $certification = Certification::factory()->published()->create();
        $this->enrollment = Enrollment::factory()->for($this->student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($this->enrollment)->for($exam)->create(['pass' => true]);
    }

    private function useRenderer(CertificatePdfRenderer $renderer): void
    {
        $this->app->instance(CertificatePdfRenderer::class, $renderer);
    }

    private function receive(): TestResponse
    {
        return $this->actingAs($this->student)->post(route('enrollments.receiveCertificate', $this->enrollment));
    }

    public function test_receiving_certificate_generates_pdf_in_private_storage(): void
    {
        $this->useRenderer(new class implements CertificatePdfRenderer
        {
            public function render(Certificate $certificate): string
            {
                return '%PDF-fake-'.$certificate->id;
            }
        });

        $this->receive()->assertRedirect(route('enrollments.show', $this->enrollment));

        $certificate = Certificate::sole();
        Storage::disk('private')->assertExists($certificate->pdf_path);
        $this->assertSame('%PDF-fake-'.$certificate->id, Storage::disk('private')->get($certificate->pdf_path));
        $this->assertSame(EnrollmentStatus::Passed, $this->enrollment->fresh()->status);
        // Web から直接アクセスできる public ディスクには置かない
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_pdf_failure_rolls_back_certificate_and_completion_and_returns_500(): void
    {
        $this->useRenderer(new class implements CertificatePdfRenderer
        {
            public function render(Certificate $certificate): string
            {
                throw new RuntimeException('mpdf failed');
            }
        });

        $this->receive()->assertStatus(500);

        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame(EnrollmentStatus::Learning, $this->enrollment->fresh()->status);
        $this->assertNull($this->enrollment->fresh()->passed_at);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_partially_written_file_is_deleted_when_generation_fails(): void
    {
        $this->useRenderer(new class implements CertificatePdfRenderer
        {
            public function render(Certificate $certificate): string
            {
                // 途中まで書き込まれた状態を再現してから失敗させる
                Storage::disk('private')->put($certificate->pdf_path, '%PDF-partial');

                throw new RuntimeException('failed after partial write');
            }
        });

        $this->receive()->assertStatus(500);

        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_second_receive_is_rejected_and_keeps_single_certificate(): void
    {
        $this->useRenderer(new class implements CertificatePdfRenderer
        {
            public function render(Certificate $certificate): string
            {
                return '%PDF-fake';
            }
        });

        $this->receive()->assertRedirect();
        $this->actingAs($this->student)
            ->postJson(route('enrollments.receiveCertificate', $this->enrollment))
            ->assertForbidden();

        $this->assertDatabaseCount('certificates', 1);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }
}
