<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 追加面談パックの購入記録(Stripe Checkout 1 セッション = 1 行)。
 *
 * amount(円) / quantity(面談回数) は購入時点のスナップショット。決済確定時に MeetingQuotaTransaction(purchased)
 * を 1 件起票して残数へ加算する。会計監査要件のため SoftDelete を採用。
 *
 * 関連: User(購入者) / MeetingPack(購入対象) / MeetingQuotaTransaction(purchased、決済確定時のみ)
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'meeting_pack_id',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'amount',
        'quantity',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'integer',
        'quantity' => 'integer',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MeetingPack, $this>
     */
    public function meetingPack(): BelongsTo
    {
        return $this->belongsTo(MeetingPack::class);
    }

    /**
     * @return HasOne<MeetingQuotaTransaction, $this>
     */
    public function quotaTransaction(): HasOne
    {
        return $this->hasOne(MeetingQuotaTransaction::class, 'related_payment_id');
    }
}
