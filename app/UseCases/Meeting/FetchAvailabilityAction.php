<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 受講登録の資格について、指定日の空き枠(開始 / 終了 / 予約可能なコーチ数)を取得するユースケース。
 */
final class FetchAvailabilityAction
{
    public function __construct(private readonly MeetingAvailabilityService $availabilityService) {}

    /**
     * @return Collection<int, array{slot_start: Carbon, slot_end: Carbon, available_coach_count: int}>
     */
    public function __invoke(Enrollment $enrollment, Carbon $date): Collection
    {
        return $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date,
        );
    }
}
