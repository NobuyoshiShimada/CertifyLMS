<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Certificate;
use App\Models\User;

/**
 * 修了証 PDF のダウンロード認可(ロール × 当事者 × 担当資格)。
 *
 * - 管理者: 全件
 * - 受講生: 本人の修了証のみ(学習中以外のステータスでも可。修了証は本人の永続資産)
 * - コーチ: 担当資格に紐づく修了証のみ
 */
class CertificatePolicy
{
    public function download(User $auth, Certificate $certificate): bool
    {
        return match ($auth->role) {
            UserRole::Admin => true,
            UserRole::Student => $certificate->user_id === $auth->id,
            UserRole::Coach => $certificate->certification()
                ->whereHas('coaches', fn ($q) => $q->whereKey($auth->id))
                ->exists(),
            default => false,
        };
    }
}
