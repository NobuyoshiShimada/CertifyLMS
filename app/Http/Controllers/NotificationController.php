<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\NotificationQueryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;

/**
 * Class NotificationController
 *
 * 受講生およびコーチ向けの通知一覧表示、詳細表示、および既読化・リダイレクト制御を担当するWebコントローラー。
 */
class NotificationController extends Controller
{
    /**
     * 通知クエリの隠蔽を担当するサービス層
     *
     * @var NotificationQueryService
     */
    protected NotificationQueryService $notificationQuery;

    /**
     * NotificationController constructor.
     *
     * @param NotificationQueryService $notificationQuery 通知クエリサービス
     */
    public function __construct(NotificationQueryService $notificationQuery)
    {
        $this->notificationQuery = $notificationQuery;
    }

    /**
     * 通知一覧画面の表示
     *
     * クエリパラメータ 'tab' (all|unread) に応じて、表示する通知のフィルタリングを行います。
     *
     * @param Request $request HTTPリクエストオブジェクト
     *
     * @return View 通知一覧のBladeビューレスポンス
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $tab = (string) $request->query('tab', 'all');
        $onlyUnread = ($tab === 'unread');

        $notifications = $this->notificationQuery->getPaginatedNotificationsForUser($user, $onlyUnread);

        // 型安全を完全に担保しエディタのエラーを排除するビルダー直接駆動クエリ
        $unreadCount = DatabaseNotification::where('notifiable_id', $user->id)
            ->where('notifiable_type', get_class($user))
            ->unread()
            ->count();

        return view('notifications.index', compact('notifications', 'unreadCount', 'tab'));
    }

    /**
     * 通知詳細ページの表示（自己完結型お知らせ用）
     *
     * 運営からのお知らせなど、外部への遷移先URLを持たない通知の全文を表示します。
     *
     * @param DatabaseNotification $notification ルートモデルバインディングされた通知モデル
     *
     * @return View 通知詳細のBladeビューレスポンス
     *
     * @throws AuthorizationException ログインユーザーが所有者ではない場合
     */
    public function show(DatabaseNotification $notification): View
    {
        $this->authorize('update', $notification);

        if ($notification->unread()) {
            $notification->markAsRead();
        }

        return view('notifications.show', compact('notification'));
    }

    /**
     * 個別通知の既読化 ＆ 適切な業務画面へのリダイレクト
     *
     * ルーティング定義の単数形 {notification} に完全同期させます。
     * これにより、モデル解決が正常化し、403および404エラーが完全に解消されます。
     *
     * @param DatabaseNotification $notification ルートモデルバインディングされた通知モデル
     *
     * @return RedirectResponse 遷移先、または詳細画面へのリダイレクト
     *
     * @throws AuthorizationException ログインユーザーが所有者ではない場合
     */
    public function read(DatabaseNotification $notification): RedirectResponse
    {
        $this->authorize('update', $notification);

        if ($notification->unread()) {
            $notification->markAsRead();
        }

        /** @var array{notification_type?: string, url?: string} $data */
        $data = $notification->data;
        $type = $data['notification_type'] ?? null;

        // 自己完結型の通知（運営お知らせなど）、またはURLが空の場合は独自の詳細画面へリダイレクト
        if ($type === 'admin_announcement' || empty($data['url'])) {
            return redirect()->route('notifications.show', ['notification' => $notification->id]);
        }

        // Q&A掲示板（/qa-board/{id}）などの業務画面へ安全に転送
        return redirect()->to($data['url']);
    }

    /**
     * ログインユーザーの全未読通知の一括既読化処理
     *
     * @return RedirectResponse 直前の画面に戻るリダイレクトレスポンス
     */
    public function markAllAsRead(): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        DatabaseNotification::where('notifiable_id', (string) $user->id)
            ->where(function ($query) {
                $query->where('notifiable_type', 'App\Models\User')
                    ->orWhere('notifiable_type', 'User');
            })
            ->unread()
            ->update(['read_at' => now()]);

        return redirect()->back()->with('status', 'すべての通知を既読にしました。');
    }
}
