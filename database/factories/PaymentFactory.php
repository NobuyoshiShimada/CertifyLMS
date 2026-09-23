<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->student()->inProgress(),
            'meeting_pack_id' => MeetingPack::factory()->published(),
            'stripe_checkout_session_id' => 'cs_test_'.Str::random(24),
            'stripe_payment_intent_id' => null,
            'amount' => 5000,
            'quantity' => 1,
            'status' => PaymentStatus::Pending->value,
            'paid_at' => null,
        ];
    }

    public function forPack(MeetingPack $pack): static
    {
        return $this->state(fn () => [
            'meeting_pack_id' => $pack->id,
            'amount' => $pack->price,
            'quantity' => $pack->meeting_count,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Pending->value, 'paid_at' => null]);
    }

    public function succeeded(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::Succeeded->value,
            'stripe_payment_intent_id' => 'pi_test_'.Str::random(24),
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => PaymentStatus::Failed->value, 'paid_at' => null]);
    }
}
