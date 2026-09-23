<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * 公開中でない面談パック(下書き / アーカイブ)を購入しようとした場合の例外(422)。
 */
final class MeetingPackNotPurchasableException extends UnprocessableEntityHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('公開中の面談パックのみ購入できます。', $previous);
    }
}
