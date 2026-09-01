<?php

namespace App\Http\Controllers;

use App\Services\NotificationQueryService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;

/**
 * Class NotificationController
 *
 * 受講生およびコーチ向けの通知一覧表示、詳細表示、および既読化制御を担当するコントローラー。
 *
 * @package App\Http\Controllers
 */
class NotificationController extends Controller
{
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
     * クエリパラメータ 'tab' (all|unread) に応じて、表示する通知を切り替えます。
     *
     * @param Request $request HTTPリクエストオブジェクト
     * @return View 通知一覧のBladeビューレスポンス
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $tab = (string) $request->query('tab', 'all');
        $onlyUnread = ($tab === 'unread');

        $notifications = $this->notificationQuery->getPaginatedNotificationsForUser($user, $onlyUnread);

        $unreadCount = (int) $user->unreadNotifications()->count();

        return view('notifications.index', compact('notifications', 'unreadCount', 'tab'));
    }

    /**
     * 通知詳細ページの表示
     *
     * 運営からのお知らせなど、遷移先URLを持たない通知の全文を表示します。
     * 閲覧された未読通知は、自動的に既読状態へ更新されます。
     *
     * @param string $id データベース通知のUUID
     * @return View 通知詳細のBladeビューレスポンス
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 通知が見つからない場合
     * @throws \Illuminate\Auth\Access\AuthorizationException 他人の通知にアクセスした場合
     */
    public function show(string $id): View
    {
        $notification = DatabaseNotification::findOrFail($id);

        $this->authorize('update', $notification);

        if ($notification->unread()) {
            $notification->markAsRead();
        }
        return view('notifications.show', compact('notification'));
    }

     /**
     * 個別通知の既読化 ＆ 適切な遷移先へのリダイレクト
     *
     * 通知種別やURLの有無を判定し、業務画面（Q&Aスレッド等）または通知詳細画面へ振り分けます。
     *
     * @param string $id データベース通知のUUID
     * @return RedirectResponse 遷移先へのリダイレクトレスポンス
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 通知が見つからない場合
     * @throws \Illuminate\Auth\Access\AuthorizationException 他人の通知にアクセスした場合
     */
    public function read(string $id): RedirectResponse
    {
        $notification = DatabaseNotification::findOrFail($id);

        $this->authorize('update', $notification);

        if ($notification->unread()) {
            $notification->markAsRead();
        }

        $data = $notification->data;
        $type = $data['notification_type'] ?? null;

        if ($type === 'admin_announcement' || empty($data['url'])) {
            return redirect()->route('notifications.show', $notification->id);
        }
        return redirect()->to($data['url']);
    }

    /**
     * ログインユーザーの全未読通知の一括既読化
     *
     * @return RedirectResponse 直前の画面へのリダイレクトレスポンス
     */
    public function markAllAsRead(): RedirectResponse
    {
        $user = Auth::user();

        $user->unreadNotifications->markAsRead();

        return redirect()->back()->with('status', 'すべての通知を既読にしました。');
    }
}
