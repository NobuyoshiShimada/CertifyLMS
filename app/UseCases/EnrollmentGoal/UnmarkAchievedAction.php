<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentGoal;

use App\Models\EnrollmentGoal;

/**
 * 個人目標を未達成に戻すユースケース。既に未達成でもエラーにせず、達成日時を空にする(冪等)。
 * 達成 → 解除の履歴は残さない。
 */
final class UnmarkAchievedAction
{
    public function __invoke(EnrollmentGoal $goal): EnrollmentGoal
    {
        $goal->update(['achieved_at' => null]);

        return $goal;
    }
}
