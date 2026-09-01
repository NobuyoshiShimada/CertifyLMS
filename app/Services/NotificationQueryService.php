<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

class NotificationQueryService
{
    /**
     * ユーザーの通知一覧をページネーション付きで取得する
     *
     * @param User $user
     * @param bool $onlyUnread 未読のみに絞り込むか
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedNotificationsForUser(User $user, bool $onlyUnread = false, int $perPage = 20): LengthAwarePaginator
    {
        return $user->notifications()
        ->when($onlyUnread, function ($query) {
            return $query->unread();
        })
        ->orderBy('created_at', 'desc')
        ->paginate($perPage);
    }
}
