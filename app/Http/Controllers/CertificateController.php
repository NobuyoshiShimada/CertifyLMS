<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\UseCases\Certificate\IssueAction;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 修了証 PDF のダウンロード。private ディスクからファイル添付形式(certificate-{修了証 ID}.pdf)でストリーミング配信する。
 *
 * ルートには学習中ガードを掛けない(修了後の受講生も本人の修了証をダウンロードできる)。
 * 認可は CertificatePolicy(本人 / 担当コーチ / 管理者)、PDF 実体が保管領域に無ければ 404。
 */
class CertificateController extends Controller
{
    public function download(Certificate $certificate): StreamedResponse
    {
        $this->authorize('download', $certificate);

        $disk = Storage::disk(IssueAction::DISK);
        abort_unless($disk->exists($certificate->pdf_path), 404, '修了証 PDF が見つかりません。');

        return $disk->download($certificate->pdf_path, "certificate-{$certificate->id}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
