<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Certificate;
use App\Services\Certificate\CertificatePdfRenderer;
use App\UseCases\Certificate\IssueAction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * 投入済みの修了証すべてに、PDF 実体を private ディスクへ生成する(EnrollmentSeeder / CertificateSeeder の後に実行)。
 *
 * 担当コーチの異なる資格の修了証が PDF 実体込みで揃い、本人 / 担当コーチ / 管理者のダウンロード成功と
 * 担当外コーチのアクセス不可を実機で確認できる状態にする。
 */
class CertificatePdfSeeder extends Seeder
{
    public function run(CertificatePdfRenderer $renderer): void
    {
        $disk = Storage::disk(IssueAction::DISK);
        $count = 0;

        Certificate::query()->with(['user', 'certification'])->each(function (Certificate $certificate) use ($disk, $renderer, &$count): void {
            $disk->put($certificate->pdf_path, $renderer->render($certificate));
            $count++;
        });

        $this->command?->info("CertificatePdfSeeder: 修了証 PDF を {$count} 件生成しました。");
    }
}
