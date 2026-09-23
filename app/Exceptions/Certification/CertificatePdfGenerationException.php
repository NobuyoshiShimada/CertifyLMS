<?php

declare(strict_types=1);

namespace App\Exceptions\Certification;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * 修了証 PDF の生成 / 保存に失敗した場合の例外(500)。発行処理のトランザクションごと巻き戻させる。
 */
final class CertificatePdfGenerationException extends HttpException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(500, '修了証 PDF の生成に失敗しました。時間をおいて再度お試しください。', $previous);
    }
}
