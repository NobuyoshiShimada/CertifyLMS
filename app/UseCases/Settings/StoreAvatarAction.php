<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * アバター画像を差し替えるユースケース。
 *
 * 1. 新ファイルを保存(失敗したら既存アバターは一切変更しない)
 * 2. avatar_url を新しい URL に更新
 * 3. 旧ファイルをベストエフォートで削除(失敗しても操作は成功扱い)
 */
final class StoreAvatarAction
{
    public function __construct(private readonly AvatarStorageHelper $storage) {}

    public function __invoke(User $user, UploadedFile $file): User
    {
        $path = $file->store(AvatarStorageHelper::DIRECTORY, AvatarStorageHelper::DISK);
        if ($path === false) {
            throw new RuntimeException('アイコン画像の保存に失敗しました。');
        }

        $oldUrl = $user->avatar_url;

        $user->update(['avatar_url' => $this->storage->url($path)]);

        $this->storage->deleteQuietly($oldUrl);

        return $user;
    }
}
