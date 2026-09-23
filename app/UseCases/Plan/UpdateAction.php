<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * プランの基本情報を更新するユースケース。状態(status)はここでは変更しない
 * (publish / archive / unarchive の専用アクションでのみ遷移する)。
 *
 * 受講中ユーザーの受講期間 / 面談回数は招待時に User 側へ確定済みのため、ここでの変更は遡及しない。
 */
final class UpdateAction
{
    /**
     * @param array{name: string, description?: ?string, duration_days: int, default_meeting_quota: int, sort_order?: ?int} $data
     */
    public function __invoke(Plan $plan, User $admin, array $data): Plan
    {
        return DB::transaction(function () use ($plan, $admin, $data) {
            $plan->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'duration_days' => $data['duration_days'],
                'default_meeting_quota' => $data['default_meeting_quota'],
                'sort_order' => $data['sort_order'] ?? 0,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
