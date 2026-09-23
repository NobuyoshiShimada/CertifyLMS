<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\Models\User;

/**
 * 個人目標の認可。追加 / 編集 / 削除 / 達成マーク / 達成解除 は受講生本人のみ。
 *
 * 閲覧は受講登録詳細画面(EnrollmentPolicy::view)に委ね、本人 / 担当コーチ / 管理者に開く。
 * 受講登録の状態(合格済 / 不合格 等)は目標操作の可否に影響させない。
 */
class EnrollmentGoalPolicy
{
    public function create(User $auth, Enrollment $enrollment): bool
    {
        return $this->isOwner($auth, $enrollment);
    }

    public function update(User $auth, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($auth, $goal->enrollment);
    }

    public function delete(User $auth, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($auth, $goal->enrollment);
    }

    public function markAchieved(User $auth, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($auth, $goal->enrollment);
    }

    public function unmarkAchieved(User $auth, EnrollmentGoal $goal): bool
    {
        return $this->isOwner($auth, $goal->enrollment);
    }

    private function isOwner(User $auth, ?Enrollment $enrollment): bool
    {
        return $auth->role === UserRole::Student
            && $enrollment !== null
            && $enrollment->user_id === $auth->id;
    }
}
