<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\MeetingQuota\InvalidStripeSignatureException;
use App\UseCases\MeetingQuota\HandleStripeWebhookAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe からの決済イベント受信窓口(認証なし・CSRF 除外の公開エンドポイント)。
 *
 * 署名検証が唯一の正当性保証。検証失敗 / シークレット未設定は 400 で拒否し、購入記録 / 残数は変更しない。
 * 検証を通ったイベントは種別に関わらず 200 を返す(未対応種別・対応購入記録なしを含め、Stripe の再送を止める)。
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, HandleStripeWebhookAction $action): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw new InvalidStripeSignatureException;
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidStripeSignatureException($e);
        }

        $action($event);

        return response()->json(['received' => true]);
    }
}
