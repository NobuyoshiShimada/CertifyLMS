<?php

declare(strict_types=1);

namespace App\Services\Certificate;

use App\Models\Certificate;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * mpdf による修了証 PDF の生成。提供済みテンプレート(certificates/pdf.blade.php)を A4 横向きで描画する。
 *
 * 日本語は mpdf 同梱の CJK フォント(Sun-ExtA)へ自動で切り替える(autoScriptToLang / autoLangToFont)ため、
 * 追加のフォントインストールは不要。mpdf の一時ファイルは storage 配下の作業ディレクトリに置く。
 */
final class MpdfCertificatePdfRenderer implements CertificatePdfRenderer
{
    public function render(Certificate $certificate): string
    {
        $certificate->loadMissing(['user', 'certification']);

        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'tempDir' => $tempDir,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);
        $mpdf->SetTitle('修了証');
        $mpdf->WriteHTML(view('certificates.pdf', ['certificate' => $certificate])->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
