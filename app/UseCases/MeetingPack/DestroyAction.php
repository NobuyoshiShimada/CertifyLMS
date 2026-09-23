<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Exceptions\MeetingPack\MeetingPackNotDeletableException;
use App\Models\MeetingPack;
use Illuminate\Support\Facades\DB;

/**
 * 面談パックを削除するユースケース。公開中(published)の面談パックは削除不可
 * (下書き・アーカイブは削除可 — 購入履歴の整合性を守るため公開中のみ制限する)。
 */
final class DestroyAction
{
    /**
     * @throws MeetingPackNotDeletableException 公開中の面談パックは削除不可
     */
    public function __invoke(MeetingPack $plan): void
    {
        if ($plan->status === MeetingPackStatus::Published) {
            throw new MeetingPackNotDeletableException;
        }

        DB::transaction(fn () => $plan->delete());
    }
}
