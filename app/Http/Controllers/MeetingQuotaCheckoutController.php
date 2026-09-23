<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\MeetingQuota\StartCheckoutRequest;
use App\Models\MeetingPack;
use App\Models\Payment;
use App\UseCases\MeetingQuota\StartCheckoutAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 追加面談パックの購入動線(受講中の受講生のみ)。決済画面は Stripe Checkout に委譲する。
 *
 * 残数の加算は決済完了画面の表示時ではなく、決済完了 Webhook の受信時に行う(StripeWebhookController)。
 */
class MeetingQuotaCheckoutController extends Controller
{
    public function select(): View
    {
        return view('meeting-quota.checkout-select', [
            'plans' => MeetingPack::query()->published()->ordered()->get(),
        ]);
    }

    public function create(StartCheckoutRequest $request, StartCheckoutAction $action): RedirectResponse
    {
        $pack = MeetingPack::query()->findOrFail($request->validated('meeting_pack_id'));

        $result = $action($request->user(), $pack);

        return redirect()->away($result->checkoutUrl);
    }

    public function success(Request $request): View
    {
        $sessionId = $request->query('session_id');

        $payment = is_string($sessionId)
            ? Payment::query()
                ->where('user_id', $request->user()->id)
                ->where('stripe_checkout_session_id', $sessionId)
                ->with('meetingPack')
                ->first()
            : null;

        return view('meeting-quota.success', ['payment' => $payment]);
    }
}
