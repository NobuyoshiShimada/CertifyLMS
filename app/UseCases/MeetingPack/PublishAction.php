<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackInvalidTransitionException;
use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを公開する(draft → published)ユースケース。
 * 下書き以外の状態からの呼出は MeetingPackInvalidTransitionException（409）。
 */
final class PublishAction
{
    /**
     * @throws MeetingPackInvalidTransitionException 下書き以外からの呼出
     */
    public function __invoke(MeetingPack $plan, User $admin): MeetingPack
    {
        if ($plan->status !== MeetingPackStatus::Draft) {
            throw MeetingPackInvalidTransitionException::forPublish();
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
