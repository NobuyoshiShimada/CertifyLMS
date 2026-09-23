<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\User;
use App\Services\Certificate\MpdfCertificatePdfRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * mpdf による修了証 PDF の実描画(A4 横向き / 日本語フォント埋め込み)と、テンプレートに載る 6 要素を検証する。
 */
class CertificatePdfRendererTest extends TestCase
{
    use RefreshDatabase;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->certificate = Certificate::factory()->create([
            'user_id' => User::factory()->student()->create(['name' => '山田 花子'])->id,
            'certification_id' => Certification::factory()->create(['name' => '基本情報技術者試験', 'description' => '説明文テキスト'])->id,
            'issued_at' => Carbon::parse('2026-05-25 10:00:00'),
        ]);
    }

    public function test_renders_a4_landscape_pdf_with_japanese_font(): void
    {
        $pdf = (new MpdfCertificatePdfRenderer)->render($this->certificate);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('/MediaBox \[0 0 841\.890 595\.280\]/', $pdf, 'A4 横向き(842x595pt)であること');
        $this->assertMatchesRegularExpression('/Sun-ExtA/i', $pdf, '日本語(CJK)フォントが埋め込まれていること');
    }

    public function test_template_contains_only_the_six_certificate_elements(): void
    {
        $html = view('certificates.pdf', ['certificate' => $this->certificate->load(['user', 'certification'])])->render();
        // 発行元はタグで装飾されているため(Certify <span>LMS</span>)、タグを除いたテキストで確認する
        $text = (string) preg_replace('/\s+/u', ' ', strip_tags($html));

        foreach (['修了証', '上記の者は、本資格の所定の課程を修了したことを証する', 'Certify LMS', '山田 花子', '基本情報技術者試験', '2026 年 5 月 25 日'] as $element) {
            $this->assertStringContainsString($element, $text);
        }
        // 付加情報(資格の分類 / 説明など)は載せない
        $certification = $this->certificate->certification->load('category');
        $this->assertNotNull($certification->category);
        $this->assertStringNotContainsString($certification->category->name, $text);
        $this->assertStringNotContainsString('説明文テキスト', $text);
    }
}
