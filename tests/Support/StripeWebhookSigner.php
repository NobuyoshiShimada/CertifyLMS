<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stripe Webhook と同じ形式の署名ヘッダ(Stripe-Signature)を生成するテストヘルパー。
 *
 * 受信側はモックせず、既知のシークレットで正規の署名を自前生成して「本物の Stripe から届いた通知」を再現する。
 * 形式: `t={timestamp},v1={HMAC-SHA256(secret, "{timestamp}.{payload}")}`
 */
final class StripeWebhookSigner
{
    public static function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }
}
