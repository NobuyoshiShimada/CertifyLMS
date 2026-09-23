<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Models\MeetingPack;
use App\Models\User;
use Stripe\StripeClient;

/**
 * Stripe API で Checkout Session(都度払い / 円 / 1 点)を作成する実装。
 *
 * 面談パックに Stripe Price ID が紐付いていればそれを使い、無ければ価格を price_data で動的に指定する。
 */
final class StripeCheckoutSessionGateway implements CheckoutSessionGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly string $currency,
    ) {}

    public function create(User $buyer, MeetingPack $pack, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $lineItem = $pack->stripe_price_id !== null
            ? ['price' => $pack->stripe_price_id, 'quantity' => 1]
            : [
                'price_data' => [
                    'currency' => $this->currency,
                    'unit_amount' => $pack->price,
                    'product_data' => ['name' => $pack->name],
                ],
                'quantity' => 1,
            ];

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [$lineItem],
            'customer_email' => $buyer->email,
            'client_reference_id' => $buyer->id,
            'metadata' => ['user_id' => $buyer->id, 'meeting_pack_id' => $pack->id],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        return new CheckoutSession((string) $session->id, (string) $session->url);
    }
}
