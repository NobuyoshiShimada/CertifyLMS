<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを再公開する(archived → published)ユースケース。
 * アーカイブ以外の状態からの呼出は MeetingPackInvalidTransitionException（409）。
 */
final class UnarchiveAction
{
    /**
     * @throws MeetingPackInvalidTransitionException アーカイブ以外からの呼出
     */
    public function __invoke(MeetingPack $plan, User $admin): MeetingPack
    {
        if ($plan->status !== MeetingPackStatus::Archived) {
            throw MeetingPackInvalidTransitionException::forUnarchive();
        }

        return DB::transaction(function () use ($plan, $admin) {
            $plan->update([
                'status' => MeetingPackStatus::Published->value,
                'updated_by_user_id' => $admin->id,
            ]);

            return $plan->fresh();
        });
    }
}
