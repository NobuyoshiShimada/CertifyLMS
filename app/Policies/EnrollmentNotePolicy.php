<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * コーチメモの認可。受講生は閲覧含めすべて拒否する。
 *
 * - 閲覧 / 追加: 管理者(任意の受講登録)または 受講登録の資格を担当するコーチ
 * - 編集 / 削除: 管理者(越境可)または メモの作成者本人
 *
 * 受講登録の状態(合格済 / 不合格 等)はメモ操作の可否に影響させない。
 */
class EnrollmentNotePolicy
{
    public function viewAny(User $auth, Enrollment $enrollment): bool
    {
        return $this->isAdminOrAssignedCoach($auth, $enrollment);
    }

    public function create(User $auth, Enrollment $enrollment): bool
    {
        return $this->isAdminOrAssignedCoach($auth, $enrollment);
    }

    public function update(User $auth, EnrollmentNote $note): bool
    {
        return $this->isAdminOrAuthor($auth, $note);
    }

    public function delete(User $auth, EnrollmentNote $note): bool
    {
        return $this->isAdminOrAuthor($auth, $note);
    }

    private function isAdminOrAssignedCoach(User $auth, Enrollment $enrollment): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $enrollment->certification()
                ->whereHas('coaches', fn ($q) => $q->whereKey($auth->id))
                ->exists(),
            default => false,
        };
    }

    private function isAdminOrAuthor(User $auth, EnrollmentNote $note): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Coach => $note->author_user_id === $auth->id,
            default => false,
        };
    }
}
