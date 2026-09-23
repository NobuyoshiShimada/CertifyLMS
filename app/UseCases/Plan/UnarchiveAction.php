<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Exceptions\Plan\PlanInvalidTransitionException;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プランを下書きに戻す(archived → draft)ユースケース。
 *
 * 「公開中 → 下書き」への直接遷移は用意せず、必ずアーカイブを経由させる。
 * 誤アーカイブの取り戻しや再提供準備のための動線。
 */
final class UnarchiveAction
{
    /**
     * @throws PlanInvalidTransitionException Archived 以外からの呼出
     */
    public function __invoke(Plan $plan, User $admin): Plan
    {
        if ($plan->status !== PlanStatus::Archived) {
            throw PlanInvalidTransitionException::forUnarchive();
        }

        return DB::transaction(function () use ($plan, $admin) {
            $plan->update([
                'status' => PlanStatus::Draft->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
