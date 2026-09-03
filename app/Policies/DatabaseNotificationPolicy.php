<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Class DatabaseNotificationPolicy
 *
 * データベース通知レコードに対する、ユーザーのアクセスおよび操作認可を検証するポリシー。
 */
class DatabaseNotificationPolicy
{
    use HandlesAuthorization;

    /**
     * 対象の通知レコードが、ログイン中のユーザー本人宛てのものであるかを厳格に検証
     *
     * @param User $user 現在ログイン中のユーザー
     * @param DatabaseNotification $notification 操作対象のデータベース通知モデル
     *
     * @return bool 認可に成功した場合は true、他人のデータへの不正アクセスの場合は false
     */
    public function update(User $user, DatabaseNotification $notification): bool
    {
        return $notification->notifiable_id === $user->id
        && $notification->notifiable_type === get_class($user);
    }
}
