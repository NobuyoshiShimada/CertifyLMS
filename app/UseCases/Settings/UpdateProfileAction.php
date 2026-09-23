<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Enums\UserRole;
use App\Models\User;

/**
 * 本人のプロフィールを更新するユースケース。メールは更新対象に含めない。
 *
 * 固定面談 URL はコーチのみ反映する(UI での非表示に加えた 2 層目の防御)。空入力は NULL でクリアする。
 */
final class UpdateProfileAction
{
    /**
     * @param array{name: string, bio?: ?string, meeting_url?: ?string} $data
     */
    public function __invoke(User $user, array $data): User
    {
        $attributes = [
            'name' => $data['name'],
            'bio' => $data['bio'] ?? null,
        ];

        if ($user->role === UserRole::Coach) {
            $attributes['meeting_url'] = $data['meeting_url'] ?? null;
        }

        $user->update($attributes);

        return $user;
    }
}
