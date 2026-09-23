<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * 受講生 1 人あたりの AI 相談の日次送信上限を超えた場合の例外(429)。
 */
final class DailyMessageLimitExceededException extends TooManyRequestsHttpException
{
    public function __construct()
    {
        parent::__construct(null, '本日の利用上限に達しました。明日 0:00 以降に再度ご利用ください。');
    }
}
