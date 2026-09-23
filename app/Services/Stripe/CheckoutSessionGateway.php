<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\MeetingPack;
use App\Models\User;

/**
 * 決済画面(Stripe Checkout Session)を作成する窓口。
 *
 * 外部 API 呼び出しを本インターフェースの裏に隠し、テストでは実 API に依存しない実装へ差し替える。
 */
interface CheckoutSessionGateway
{
    /**
     * 面談パック 1 点分の Checkout Session を作成し、セッション ID と決済画面 URL を返す。
     */
    public function create(User $buyer, MeetingPack $pack, string $successUrl, string $cancelUrl): CheckoutSession;
}
