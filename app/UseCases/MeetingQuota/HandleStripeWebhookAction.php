<?php

declare(strict_types=1);

namespace App\UseCases\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;

/**
 * 署名検証済みの Stripe イベントを購入記録 / 残数へ反映するユースケース。
 *
 * - checkout.session.completed(payment_status=paid) / checkout.session.async_payment_succeeded
 *   → 保留 → 成功(決済確定日時 + PaymentIntent ID を記録)し、購入回数分を残数へ加算
 * - checkout.session.async_payment_failed / checkout.session.expired → 保留 → 失敗(残数は変えない)
 * - 上記以外の種別、対応する購入記録が無いイベントは何もしない(呼出元は 200 を返して再送を止める)
 *
 * 冪等性: Checkout Session ID で購入記録を行ロックし、保留状態のときだけ遷移させる。
 * 再送(二重の完了通知)は成功済みのためスキップされ、完了後に失敗 / 期限切れが遅れて届いても成功を巻き戻さない。
 * 購入記録の更新と残数加算(purchased 取引の起票)は同一トランザクションで原子的に行う。
 */
final class HandleStripeWebhookAction
{
    private const SUCCEEDED_EVENTS = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];

    private const FAILED_EVENTS = ['checkout.session.async_payment_failed', 'checkout.session.expired'];

    public function __invoke(Event $event): void
    {
        if (! in_array($event->type, [...self::SUCCEEDED_EVENTS, ...self::FAILED_EVENTS], true)) {
            return;
        }

        $session = $event->data->object;
        $sessionId = (string) ($session->id ?? '');

        if ($event->type === 'checkout.session.completed' && ($session->payment_status ?? null) !== 'paid') {
            // 銀行振込等の非同期決済は completed 時点で未確定。確定は async_payment_succeeded で行う。
            return;
        }

        DB::transaction(function () use ($event, $session, $sessionId): void {
            $payment = Payment::query()
                ->where('stripe_checkout_session_id', $sessionId)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                Log::warning('Stripe Webhook: 対応する購入記録が見つかりません', [
                    'event_id' => $event->id,
                    'type' => $event->type,
                    'checkout_session_id' => $sessionId,
                ]);

                return;
            }

            if ($payment->status !== PaymentStatus::Pending) {
                return;
            }

            if (in_array($event->type, self::FAILED_EVENTS, true)) {
                $payment->update(['status' => PaymentStatus::Failed->value]);

                return;
            }

            $paidAt = now();
            $payment->update([
                'status' => PaymentStatus::Succeeded->value,
                'stripe_payment_intent_id' => isset($session->payment_intent) ? (string) $session->payment_intent : null,
                'paid_at' => $paidAt,
            ]);

            MeetingQuotaTransaction::create([
                'user_id' => $payment->user_id,
                'type' => MeetingQuotaTransactionType::Purchased,
                'amount' => $payment->quantity,
                'related_payment_id' => $payment->id,
                'occurred_at' => $paidAt,
            ]);
        });
    }
}
