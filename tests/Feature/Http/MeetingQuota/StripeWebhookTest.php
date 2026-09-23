<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\MeetingQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe Webhook 受信(POST /webhooks/stripe)の署名検証・状態遷移・残数加算・冪等性を検証する機能テスト。
 *
 * 実 API には接続せず、既知のシークレットで Stripe と同じ形式の署名ヘッダを生成して送る。
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    private User $student;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.webhook_secret' => self::SECRET]);

        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 2]);
        $pack = MeetingPack::factory()->published()->create(['price' => 12000, 'meeting_count' => 3]);
        $this->payment = Payment::factory()->forPack($pack)->pending()->create([
            'user_id' => $this->student->id,
            'stripe_checkout_session_id' => 'cs_test_target',
        ]);
    }

    /**
     * @param array<string, mixed> $session
     */
    private function payload(string $type, array $session = []): string
    {
        return (string) json_encode([
            'id' => 'evt_'.bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => array_merge([
                'id' => 'cs_test_target',
                'object' => 'checkout.session',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_123',
            ], $session)],
        ]);
    }

    private function send(string $payload, ?string $secret = self::SECRET): TestResponse
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", (string) $secret);

        return $this->call(
            'POST',
            route('webhooks.stripe'),
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: $payload,
        );
    }

    private function remaining(): int
    {
        return app(MeetingQuotaService::class)->remaining($this->student->fresh());
    }

    public function test_completed_event_marks_succeeded_and_adds_quantity_to_remaining(): void
    {
        $before = $this->remaining();

        $this->send($this->payload('checkout.session.completed'))->assertOk();

        $this->payment->refresh();
        $this->assertSame(PaymentStatus::Succeeded, $this->payment->status);
        $this->assertNotNull($this->payment->paid_at);
        $this->assertSame('pi_test_123', $this->payment->stripe_payment_intent_id);
        $this->assertSame($before + 3, $this->remaining());
        $this->assertDatabaseHas('meeting_quota_transactions', [
            'user_id' => $this->student->id,
            'type' => MeetingQuotaTransactionType::Purchased->value,
            'amount' => 3,
            'related_payment_id' => $this->payment->id,
        ]);
    }

    public function test_duplicate_completed_event_adds_quantity_only_once(): void
    {
        $before = $this->remaining();

        $this->send($this->payload('checkout.session.completed'))->assertOk();
        $this->send($this->payload('checkout.session.completed'))->assertOk();
        $this->send($this->payload('checkout.session.async_payment_succeeded'))->assertOk();

        $this->assertSame($before + 3, $this->remaining());
        $this->assertSame(1, MeetingQuotaTransaction::query()->where('related_payment_id', $this->payment->id)->count());
    }

    /**
     * @dataProvider failureEventProvider
     */
    public function test_failed_or_expired_event_marks_failed_without_adding(string $type): void
    {
        $before = $this->remaining();

        $this->send($this->payload($type))->assertOk();

        $this->assertSame(PaymentStatus::Failed, $this->payment->fresh()->status);
        $this->assertSame($before, $this->remaining());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failureEventProvider(): array
    {
        return [
            '決済失敗' => ['checkout.session.async_payment_failed'],
            '期限切れ' => ['checkout.session.expired'],
        ];
    }

    public function test_late_failure_after_success_does_not_roll_back(): void
    {
        $this->send($this->payload('checkout.session.completed'))->assertOk();
        $afterSuccess = $this->remaining();

        $this->send($this->payload('checkout.session.expired'))->assertOk();
        $this->send($this->payload('checkout.session.async_payment_failed'))->assertOk();

        $this->assertSame(PaymentStatus::Succeeded, $this->payment->fresh()->status);
        $this->assertSame($afterSuccess, $this->remaining());
    }

    public function test_success_after_failure_is_not_applied(): void
    {
        $before = $this->remaining();

        $this->send($this->payload('checkout.session.expired'))->assertOk();
        $this->send($this->payload('checkout.session.completed'))->assertOk();

        $this->assertSame(PaymentStatus::Failed, $this->payment->fresh()->status);
        $this->assertSame($before, $this->remaining());
    }

    public function test_completed_but_unpaid_session_stays_pending(): void
    {
        $this->send($this->payload('checkout.session.completed', ['payment_status' => 'unpaid']))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->payment->fresh()->status);
        $this->assertSame(0, MeetingQuotaTransaction::query()->where('related_payment_id', $this->payment->id)->count());
    }

    public function test_unsupported_event_type_and_unknown_session_return_200_without_changes(): void
    {
        $before = $this->remaining();

        $this->send($this->payload('customer.created'))->assertOk();
        $this->send($this->payload('checkout.session.completed', ['id' => 'cs_test_unknown']))->assertOk();

        $this->assertSame(PaymentStatus::Pending, $this->payment->fresh()->status);
        $this->assertSame($before, $this->remaining());
    }

    public function test_invalid_signature_returns_400_without_changes(): void
    {
        $before = $this->remaining();

        $this->send($this->payload('checkout.session.completed'), 'whsec_wrong')
            ->assertStatus(400)
            ->assertJson(['message' => 'Stripe Webhook の署名検証に失敗しました。']);

        $this->assertSame(PaymentStatus::Pending, $this->payment->fresh()->status);
        $this->assertSame($before, $this->remaining());
    }

    public function test_missing_signature_header_returns_400(): void
    {
        $this->call('POST', route('webhooks.stripe'), server: ['HTTP_ACCEPT' => 'application/json'], content: $this->payload('checkout.session.completed'))
            ->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $this->payment->fresh()->status);
    }

    public function test_unconfigured_webhook_secret_returns_400(): void
    {
        config(['services.stripe.webhook_secret' => null]);

        $this->send($this->payload('checkout.session.completed'))->assertStatus(400);

        $this->assertSame(PaymentStatus::Pending, $this->payment->fresh()->status);
    }

    public function test_webhook_endpoint_requires_no_authentication_and_is_csrf_exempt(): void
    {
        $route = app('router')->getRoutes()->getByName('webhooks.stripe');

        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertContains('webhooks/stripe', (fn () => $this->except)->call(app(VerifyCsrfToken::class)));
    }
}
