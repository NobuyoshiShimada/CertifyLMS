<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Class NotificationQueryService
 *
 * 通知に関連する複雑なデータベースクエリの構築、ページネーション、およびフィルタリングを隠蔽するQuery Service。
 */
class NotificationQueryService
{
    /**
     * 特定ユーザーの通知一覧をページネーション付きの新着順で取得
     *
     * @param User $user 対象のユーザーモデルインスタンス
     * @param bool $onlyUnread 未読のみに厳格に絞り込むかどうか
     * @param int $perPage 1ページあたりの表示件数（デフォルト: 20件）
     *
     * @return LengthAwarePaginator ページネーションデータを含む結果オブジェクト
     */
    public function getPaginatedNotificationsForUser(User $user, bool $onlyUnread = false, int $perPage = 20): LengthAwarePaginator
    {
        // 確実な Eloquent ビルダー直接駆動にリファクタリングして404や空表示を撲滅します
        return DatabaseNotification::where('notifiable_id', (string) $user->id)
            ->where(function ($query) {
                $query->where('notifiable_type', 'App\Models\User')
                    ->orWhere('notifiable_type', 'User');
            })
            ->when($onlyUnread, function ($query) {
                return $query->unread();
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }
}
