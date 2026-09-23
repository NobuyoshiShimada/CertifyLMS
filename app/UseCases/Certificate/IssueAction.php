<?php

declare(strict_types=1);

namespace App\UseCases\Certificate;

use App\Enums\EnrollmentStatus;
use App\Exceptions\Certification\CertificateAlreadyIssuedException;
use App\Exceptions\Certification\CertificatePdfGenerationException;
use App\Exceptions\Certification\EnrollmentNotPassedException;
use App\Models\Certificate;
use App\Models\Enrollment;
use App\Services\Certificate\CertificatePdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * 修了証を発行するユースケース。受講生自己発火型の修了処理 `\App\UseCases\Enrollment\ReceiveCertificateAction` から呼び出される。
 *
 * 業務分岐:
 * - Enrollment が `status=passed` + `passed_at != null` でない: EnrollmentNotPassedException（409）
 * - 同一 Enrollment に対する二重呼出: CertificateAlreadyIssuedException（409、事前 lockForUpdate + exists で検出）
 *
 * 修了証レコードの INSERT と PDF の生成・保存は同じ `DB::transaction()` 内で同期実行する。
 * PDF の生成 / 保存に失敗した場合は、書き込まれた可能性のある部分ファイルを削除してから
 * CertificatePdfGenerationException(500)を投げ、レコード作成(と呼出元の修了遷移)ごと巻き戻す。
 * PDF は Web から直接アクセスできない private ディスクに保存し、ダウンロードは CertificateController 経由のみ。
 */
final class IssueAction
{
    public const DISK = 'private';

    public function __construct(private readonly CertificatePdfRenderer $renderer) {}

    /**
     * @throws EnrollmentNotPassedException 受講登録が修了状態ではない
     * @throws CertificateAlreadyIssuedException 同一 Enrollment で修了証が既発行
     * @throws CertificatePdfGenerationException PDF の生成 / 保存に失敗
     */
    public function __invoke(Enrollment $enrollment): Certificate
    {
        if ($enrollment->status !== EnrollmentStatus::Passed || $enrollment->passed_at === null) {
            throw new EnrollmentNotPassedException;
        }

        return DB::transaction(function () use ($enrollment) {
            // 二重発行ガード: lockForUpdate で同時呼出を直列化し、enrollment_id UNIQUE 違反を例外メッセージ判別ではなく事前 SELECT で確定検出する
            $existing = Certificate::query()
                ->where('enrollment_id', $enrollment->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new CertificateAlreadyIssuedException;
            }

            $certificate = Certificate::create([
                'user_id' => $enrollment->user_id,
                'enrollment_id' => $enrollment->id,
                'certification_id' => $enrollment->certification_id,
                'pdf_path' => 'certificates/'.Str::ulid().'.pdf',
                'issued_at' => now(),
            ]);

            $this->storePdf($certificate);

            return $certificate;
        });
    }

    /**
     * @throws CertificatePdfGenerationException
     */
    private function storePdf(Certificate $certificate): void
    {
        $disk = Storage::disk(self::DISK);

        try {
            if (! $disk->put($certificate->pdf_path, $this->renderer->render($certificate))) {
                throw new RuntimeException('修了証 PDF を保管領域へ書き込めませんでした。');
            }
        } catch (Throwable $e) {
            // 部分書き込みの孤立ファイルを残さない
            try {
                $disk->delete($certificate->pdf_path);
            } catch (Throwable) {
                // 削除できなくても、発行の巻き戻し(例外の送出)を優先する
            }

            Log::error('修了証 PDF の生成に失敗したため発行を巻き戻します', [
                'enrollment_id' => $certificate->enrollment_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw new CertificatePdfGenerationException($e);
        }
    }
}
