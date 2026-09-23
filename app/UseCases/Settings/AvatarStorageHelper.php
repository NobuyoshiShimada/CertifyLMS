<?php

declare(strict_types=1);

namespace App\UseCases\Settings;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * アバター画像を public ディスクの avatars/ 配下で扱うヘルパー。
 *
 * users.avatar_url には表示にそのまま使える公開 URL を保存するため、削除時は URL からディスク上のパスを逆算する。
 * 本ディスク配下にない URL(外部画像など)は削除対象にしない。
 */
final class AvatarStorageHelper
{
    public const DISK = 'public';

    public const DIRECTORY = 'avatars';

    /**
     * 旧画像をベストエフォートで削除する。失敗してもユーザー操作は成功扱いにするため例外は外に出さない。
     */
    public function deleteQuietly(?string $url): void
    {
        $path = $this->pathFromUrl($url);
        if ($path === null) {
            return;
        }

        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function url(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }

    private function pathFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $prefix = rtrim(Storage::disk(self::DISK)->url(''), '/').'/';
        if (! Str::startsWith($url, $prefix)) {
            return null;
        }

        $path = Str::after($url, $prefix);

        return Str::startsWith($path, self::DIRECTORY.'/') ? $path : null;
    }
}
