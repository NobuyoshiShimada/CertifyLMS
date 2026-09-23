<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 追加面談購入(Payment)の決済状態。
 *
 * pending(Checkout 開始・決済未確定) → succeeded(決済完了、残数加算済) / failed(決済失敗・期限切れ)。
 * refunded は Stripe ダッシュボードでの手動返金を表す予約値(本 Feature では自動遷移しない)。
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '決済待ち',
            self::Succeeded => '決済完了',
            self::Failed => '決済失敗',
            self::Refunded => '返金済み',
        };
    }
}
