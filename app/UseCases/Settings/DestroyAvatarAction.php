<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;

/**
 * アバター画像を削除し、未設定状態(氏名の先頭 1 文字表示)に戻すユースケース。
 */
final class DestroyAvatarAction
{
    public function __construct(private readonly AvatarStorageHelper $storage) {}

    public function __invoke(User $user): User
    {
        $oldUrl = $user->avatar_url;

        $user->update(['avatar_url' => null]);

        $this->storage->deleteQuietly($oldUrl);

        return $user;
    }
}
