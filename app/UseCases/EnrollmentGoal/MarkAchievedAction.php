<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人目標を達成済にするユースケース。既に達成済でもエラーにせず、達成日時を現在時刻で上書きする(冪等)。
 */
final class MarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        $goal->update(['achieved_at' => now()]);

        return $goal;
    }
}
