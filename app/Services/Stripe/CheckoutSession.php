<?php

declare(strict_types=1);

namespace App\Services\Stripe;

/**
 * 作成済み Checkout Session の識別子と、受講生を遷移させる決済画面 URL。
 */
final class CheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
