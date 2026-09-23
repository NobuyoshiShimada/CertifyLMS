<?php

declare(strict_types=1);

namespace App\Services\Certificate;

use App\Models\Certificate;

/**
 * 修了証 PDF のバイナリを生成する窓口。
 *
 * PDF ライブラリ(mpdf)を本インターフェースの裏に隠し、発行フローのテストでは生成をスタブして高速化する。
 */
interface CertificatePdfRenderer
{
    /**
     * 修了証 1 件分の PDF(A4 横向き・日本語)を生成してバイナリで返す。
     */
    public function render(Certificate $certificate): string;
}
