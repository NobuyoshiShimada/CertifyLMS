<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingPackStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\MeetingQuota\MeetingPackNotPurchasableException;
use App\Exceptions\MeetingQuota\PurchaseNotAllowedException;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Stripe\CheckoutSessionGateway;
use Illuminate\Support\Facades\DB;

/**
 * 追加面談パックの購入を開始するユースケース。
 *
 * 購入ガード(受講中の受講生 / 公開中のパック)を確認し、Stripe Checkout Session を作成して
 * 保留(pending)状態の購入記録を 1 件作る。決済額 / 購入回数は購入時点の値をスナップショット保存する。
 * 残数の加算はここでは行わず、決済完了 Webhook の受信時(HandleStripeWebhookAction)に行う。
 */
final class StartCheckoutAction
{
    public function __construct(private readonly CheckoutSessionGateway $gateway) {}

    /**
     * @throws PurchaseNotAllowedException 受講中の受講生以外
     * @throws MeetingPackNotPurchasableException 公開中でない面談パック
     */
    public function __invoke(User $buyer, MeetingPack $pack): StartCheckoutResult
    {
        if ($buyer->role !== UserRole::Student || $buyer->status !== UserStatus::InProgress) {
            throw new PurchaseNotAllowedException;
        }

        if ($pack->status !== MeetingPackStatus::Published) {
            throw new MeetingPackNotPurchasableException;
        }

        $session = $this->gateway->create(
            $buyer,
            $pack,
            successUrl: route('meeting-quota.checkout.success').'?session_id={CHECKOUT_SESSION_ID}',
            cancelUrl: route('dashboard.index'),
        );

        $payment = DB::transaction(fn () => Payment::create([
            'user_id' => $buyer->id,
            'meeting_pack_id' => $pack->id,
            'stripe_checkout_session_id' => $session->id,
            'amount' => $pack->price,
            'quantity' => $pack->meeting_count,
            'status' => PaymentStatus::Pending->value,
        ]));

        return new StartCheckoutResult($payment, $session->url);
    }
}
