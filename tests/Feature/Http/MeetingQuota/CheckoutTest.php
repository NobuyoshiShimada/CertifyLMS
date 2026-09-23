<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MeetingQuota;

use App\Enums\PaymentStatus;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\Models\User;
use App\Services\Stripe\CheckoutSession;
use App\Services\Stripe\CheckoutSessionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 追加面談パックの購入動線(一覧 / 購入開始 / 完了画面)と購入ガードを検証する機能テスト。
 *
 * Stripe Checkout Session の作成は偽のゲートウェイに差し替え、実 API には接続しない。
 */
#[Group('external')]
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    /** @var array<int, array{string, string}> 偽ゲートウェイに渡された (user_id, meeting_pack_id) */
    private array $gatewayCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->inProgress()->create();

        $calls = &$this->gatewayCalls;
        $this->app->instance(CheckoutSessionGateway::class, new class($calls) implements CheckoutSessionGateway
        {
            /** @param array<int, array{string, string}> $calls */
            public function __construct(private array &$calls) {}

            public function create(User $buyer, MeetingPack $pack, string $successUrl, string $cancelUrl): CheckoutSession
            {
                $this->calls[] = [$buyer->id, $pack->id];
                $id = 'cs_test_'.count($this->calls);

                return new CheckoutSession($id, "https://checkout.stripe.test/pay/{$id}");
            }
        });
    }

    public function test_select_lists_only_published_packs_in_sort_order(): void
    {
        $second = MeetingPack::factory()->published()->create(['name' => '二番目', 'sort_order' => 2]);
        $first = MeetingPack::factory()->published()->create(['name' => '一番目', 'sort_order' => 1]);
        MeetingPack::factory()->draft()->create(['name' => '下書きパック']);
        MeetingPack::factory()->archived()->create(['name' => 'アーカイブパック']);

        $response = $this->actingAs($this->student)->get(route('meeting-quota.checkout.select'));

        $response->assertOk();
        $this->assertSame([$first->id, $second->id], $response->viewData('plans')->pluck('id')->all());
        $response->assertDontSee('下書きパック')->assertDontSee('アーカイブパック');
    }

    public function test_create_redirects_to_stripe_and_stores_pending_payment_with_snapshot(): void
    {
        $pack = MeetingPack::factory()->published()->create(['price' => 8000, 'meeting_count' => 2]);

        $this->actingAs($this->student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect('https://checkout.stripe.test/pay/cs_test_1');

        $payment = Payment::sole();
        $this->assertSame($this->student->id, $payment->user_id);
        $this->assertSame('cs_test_1', $payment->stripe_checkout_session_id);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(8000, $payment->amount);
        $this->assertSame(2, $payment->quantity);

        // 後から管理者がマスタを変更しても購入時点の値は変わらない
        $pack->update(['price' => 99999, 'meeting_count' => 9]);
        $this->assertSame(8000, $payment->fresh()->amount);
        $this->assertSame(2, $payment->fresh()->quantity);
    }

    public function test_multiple_checkouts_create_independent_pending_payments(): void
    {
        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($this->student)->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id]);
        $this->actingAs($this->student)->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id]);

        $this->assertSame(2, Payment::query()->where('status', PaymentStatus::Pending)->count());
    }

    /**
     * @dataProvider unpublishedStateProvider
     */
    public function test_unpublished_pack_is_rejected_with_422_even_by_direct_id(string $state): void
    {
        $pack = MeetingPack::factory()->{$state}()->create();

        $this->actingAs($this->student)
            ->postJson(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertStatus(422)
            ->assertJson(['message' => '公開中の面談パックのみ購入できます。']);

        $this->actingAs($this->student)
            ->from(route('meeting-quota.checkout.select'))
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertRedirect(route('meeting-quota.checkout.select'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame([], $this->gatewayCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unpublishedStateProvider(): array
    {
        return ['下書き' => ['draft'], 'アーカイブ' => ['archived']];
    }

    public function test_unknown_pack_id_is_a_validation_error(): void
    {
        $this->actingAs($this->student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => 'not-exists'])
            ->assertSessionHasErrors('meeting_pack_id');

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * @dataProvider nonLearningStudentProvider
     */
    public function test_non_learning_student_cannot_purchase(string $state): void
    {
        $student = User::factory()->student()->{$state}()->create();
        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($student)->get(route('meeting-quota.checkout.select'))->assertForbidden();
        $this->actingAs($student)
            ->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonLearningStudentProvider(): array
    {
        return ['招待中' => ['invited'], '修了' => ['graduated'], '退会' => ['withdrawn']];
    }

    /**
     * @dataProvider staffProvider
     */
    public function test_coach_and_admin_cannot_access_checkout(string $role): void
    {
        $user = User::factory()->{$role}()->inProgress()->create();
        $pack = MeetingPack::factory()->published()->create();

        $this->actingAs($user)->get(route('meeting-quota.checkout.select'))->assertForbidden();
        $this->actingAs($user)->post(route('meeting-quota.checkout.create'), ['meeting_pack_id' => $pack->id])->assertForbidden();
        $this->actingAs($user)->get(route('meeting-quota.checkout.success'))->assertForbidden();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffProvider(): array
    {
        return ['コーチ' => ['coach'], '管理者' => ['admin']];
    }

    public function test_success_page_shows_own_payment_and_reflection_notice(): void
    {
        $pack = MeetingPack::factory()->published()->create(['name' => '3回パック']);
        $payment = Payment::factory()->forPack($pack)->pending()->create(['user_id' => $this->student->id]);

        $this->actingAs($this->student)
            ->get(route('meeting-quota.checkout.success', ['session_id' => $payment->stripe_checkout_session_id]))
            ->assertOk()
            ->assertSee('3回パック')
            ->assertSee('決済が確定次第')
            ->assertSee(route('dashboard.index'), false);
    }

    public function test_success_page_does_not_show_other_users_payment(): void
    {
        $other = Payment::factory()->create();

        $response = $this->actingAs($this->student)
            ->get(route('meeting-quota.checkout.success', ['session_id' => $other->stripe_checkout_session_id]));

        $response->assertOk();
        $this->assertNull($response->viewData('payment'));
    }
}
