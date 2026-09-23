<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanNotDeletableException;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;

/**
 * プランを物理削除するユースケース。
 *
 * 「下書き状態」かつ「受講者が 1 名も紐づいていない」場合のみ削除できる(2 段ガード)。
 * 受講者の判定には退会(論理削除)済みユーザーとプラン履歴も含める。いずれも plans への FK が
 * restrictOnDelete のため、残っていると参照が孤立する(DB 制約違反になる)。
 */
final class DestroyAction
{
    /**
     * @throws PlanNotDeletableException 下書き以外 / 受講者が紐づいている
     */
    public function __invoke(Plan $plan): void
    {
        if ($plan->status !== PlanStatus::Draft) {
            throw PlanNotDeletableException::notDraft();
        }

        if ($plan->users()->withTrashed()->exists() || $plan->userPlanLogs()->exists()) {
            throw PlanNotDeletableException::hasUsers();
        }

        DB::transaction(fn () => $plan->delete());
    }
}
