<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Stripe Webhook の署名検証に失敗した(またはシークレット未設定の)場合の例外(400)。
 */
final class InvalidStripeSignatureException extends BadRequestHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Stripe Webhook の署名検証に失敗しました。', $previous);
    }
}
