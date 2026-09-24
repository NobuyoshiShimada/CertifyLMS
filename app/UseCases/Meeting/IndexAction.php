<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\Services\MeetingQuotaService;

/**
 * 受講生本人の面談一覧を取得するユースケース。filter(upcoming / past / all)で履歴を切り替え、scheduled_at 降順で並べる。
 * 一覧上部に表示する残面談回数も併せて返す。
 */
final class IndexAction
{
    public function __construct(private readonly MeetingQuotaService $meetingQuota) {}

    public function __invoke(User $student, ?string $filter, int $perPage = 20): IndexResult
    {
        $filter ??= 'upcoming';

        $query = Meeting::query()
            ->with(['enrollment.certification', 'coach'])
            ->forStudent($student)
            ->orderByDesc('scheduled_at');

        $meetings = match ($filter) {
            'past' => $query->past()->paginate($perPage),
            'all' => $query->paginate($perPage),
            default => $query->upcoming()->paginate($perPage),
        };

        return new IndexResult($meetings, $filter, $this->meetingQuota->remaining($student));
    }
}
