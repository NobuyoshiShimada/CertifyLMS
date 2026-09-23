<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人目標の内容(タイトル / 詳細 / 目標期日)を更新するユースケース。
 * 達成状態(achieved_at)は更新しない(達成マーク / 達成解除の専用操作でのみ変わる)。
 */
final class UpdateAction
{
    /**
     * @param array{title: string, description?: ?string, target_date?: ?string} $data
     */
    public function __invoke(EnrollmentGoal $goal, array $data): EnrollmentGoal
    {
        $goal->update([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'target_date' => $data['target_date'] ?? null,
        ]);

        return $goal;
    }
}
