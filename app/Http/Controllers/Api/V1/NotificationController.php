<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\Api\IndexRequest;
use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\NotificationQueryService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 通知ポップオーバー(JS)向けの通知 JSON API。Sanctum SPA Cookie 認証(auth:sanctum)で保護する。
 *
 * 返す / 操作できるのは認証ユーザー本人宛ての通知のみ。他者の通知 ID は DatabaseNotificationPolicy で 403。
 * 管理者は通知の受信側ではないため一覧は常に 0 件を返す(ポップオーバーは空状態になる)。
 * `/notifications` のフルページ(Web)とは別系統で並行して動く。
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationQueryService $notificationQuery) {}

    public function index(IndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role === UserRole::Admin) {
            return response()->json([
                'data' => [],
                'meta' => ['unread_count' => 0, 'tab' => $request->validated('tab') ?? IndexRequest::TAB_ALL],
            ]);
        }

        $notifications = $this->notificationQuery->getPaginatedNotificationsForUser(
            $user,
            onlyUnread: $request->onlyUnread(),
            perPage: $request->perPage(),
        );

        return response()->json([
            'data' => NotificationResource::collection($notifications->getCollection())->resolve($request),
            'meta' => [
                'unread_count' => $this->unreadCount($user),
                'tab' => $request->validated('tab') ?? IndexRequest::TAB_ALL,
            ],
        ]);
    }

    public function read(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $this->authorize('update', $notification);

        if ($notification->unread()) {
            $notification->markAsRead();
        }

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => (new NotificationResource($notification->refresh()))->resolve($request),
            'meta' => ['unread_count' => $this->unreadCount($user)],
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updated = $this->ownNotifications($user)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'meta' => ['unread_count' => 0, 'updated_count' => $updated],
        ]);
    }

    /**
     * 本人宛て通知のクエリ(Web 側 NotificationController / NotificationQueryService と同じ所有者条件)。
     *
     * @return Builder<DatabaseNotification>
     */
    private function ownNotifications(User $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_id', (string) $user->id)
            ->where(fn (Builder $q) => $q->where('notifiable_type', User::class)->orWhere('notifiable_type', 'User'));
    }

    private function unreadCount(User $user): int
    {
        return $this->ownNotifications($user)->whereNull('read_at')->count();
    }
}
