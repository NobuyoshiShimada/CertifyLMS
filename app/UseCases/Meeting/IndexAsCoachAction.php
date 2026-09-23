<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;

/**
 * コーチ宛の面談一覧を取得するユースケース。担当受講生 / 受講登録での絞り込みを併せて扱う。
 *
 * 並び順: upcoming は次の面談を一番上(昇順)、past / all は直近の活動を一番上(降順)。
 */
final class IndexAsCoachAction
{
    public function __invoke(
        User $coach,
        ?string $filter,
        ?string $studentId = null,
        ?string $enrollmentId = null,
        int $perPage = 20,
    ): IndexAsCoachResult {
        $filter ??= 'upcoming';

        $query = Meeting::query()
            ->with(['enrollment.certification', 'student'])
            ->forCoach($coach)
            ->when($studentId, fn ($q, $id) => $q->where('student_id', $id))
            ->when($enrollmentId, fn ($q, $id) => $q->where('enrollment_id', $id));

        $meetings = match ($filter) {
            'past' => $query->past()->orderByDesc('scheduled_at')->paginate($perPage),
            'all' => $query->orderByDesc('scheduled_at')->paginate($perPage),
            default => $query->upcoming()->orderBy('scheduled_at')->paginate($perPage),
        };

        return new IndexAsCoachResult($meetings, $filter);
    }
}
