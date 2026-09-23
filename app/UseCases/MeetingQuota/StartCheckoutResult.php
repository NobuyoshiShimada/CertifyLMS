<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Models\Payment;

/**
 * 購入開始の結果。作成した保留状態の購入記録と、受講生を遷移させる Stripe 決済画面 URL。
 */
final class StartCheckoutResult
{
    public function __construct(
        public readonly Payment $payment,
        public readonly string $checkoutUrl,
    ) {}
}
