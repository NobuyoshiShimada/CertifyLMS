<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Http\Requests\QaBoard\StoreQaThreadRequest;
use App\Http\Requests\QaBoard\UpdateQaThreadRequest;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use App\Notifications\NewQuestionPostedNotification;
use App\Services\QaThreadQueryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Class QaThreadController
 *
 * Q&Aにおける質問スレッドの作成、管理、およびそれに伴う当事者（コーチ）への即時プッシュ通知を制御するコントローラー。
 */
class QaThreadController extends Controller
{
    /**
     * @var QaThreadQueryService 質問スレッドの参照系クエリサービス
     */
    protected $qaThreadQueryService;

    /**
     * コントローラーのコンストラクタ。依存するクエリサービスを自動注入（インジェクション）する。
     *
     * @param QaThreadQueryService $qaThreadQueryService 質問スレッドの参照系クエリサービス
     */
    public function __construct(QaThreadQueryService $qaThreadQueryService)
    {
        $this->qaThreadQueryService = $qaThreadQueryService;
    }

    /**
     * 質問一覧画面を表示する。
     * リクエストの受付と、サービスから取得したデータのレスポンス返却のみを行う。
     *
     * @param Request $request リクエストオブジェクト
     *
     * @return View 質問一覧画面のビュー
     */
    public function index(Request $request): View
    {
        $filters = $request->only(['status', 'certification_id', 'keyword']);

        $threads = $this->qaThreadQueryService->getPaginatedThreads($filters, 10);

        $certifications = Certification::where('status', CertificationStatus::Published)->get();
        $publishedStatus = CertificationStatus::Published;

        return view('qa-thread.index', compact('threads', 'certifications', 'filters', 'publishedStatus'));
    }

    /**
     * 新規質問の投稿画面を表示する。
     *
     * @return View 質問投稿画面のビュー
     */
    public function create(): View
    {
        $certifications = Certification::where('status', CertificationStatus::Published)->get();

        return view('qa-thread.create', compact('certifications'));
    }

    /**
     * 新規質問スレッドをバリデーション後に保存し、担当コーチ全員へ即時（DB+メール）通知を配信
     *
     * @param Request $request HTTPリクエストオブジェクト
     *
     * @return RedirectResponse 保存完了後のQ&A詳細画面へのリダイレクト
     */
    public function store(StoreQaThreadRequest $request): RedirectResponse
    {
        $thread = QaThread::createWithTransaction($request->user(), $request->validated());

        $coaches = User::where('role', UserRole::Coach->value)->get();

        if ($coaches->isNotEmpty()) {
            /** @var User $currentUser */
            $currentUser = $request->user();

            $payload = [
                'title' => $thread->title,
                'message' => "受講生の {$currentUser->name} さんが新しく「{$thread->title}」を投稿しました。",
                'url' => "/qa-board/{$thread->id}", // 既読化後に案内する相対URLパス
            ];

            // 4. アプリ内通知(DB)とメールの一斉即時プッシュ送信を同時にトリガー
            Notification::send($coaches, new NewQuestionPostedNotification($payload));
        }

        return redirect()->route('qa-board.show', $thread)
            ->with('status', '質問を投稿しました。');
    }

    /**
     * 指定された質問スレッドの編集画面を表示する。
     *
     * @param QaThread $thread 編集対象の質問スレッドモデル
     *
     * @return View 質問編集画面のビュー
     */
    public function edit(QaThread $thread): View
    {
        return view('qa-thread.edit', compact('thread'));
    }

    /**
     * 質問スレッドの変更内容を保存するリクエストを受け付ける。
     *
     * @param UpdateQaThreadRequest $request フォームリクエストオブジェクト
     * @param QaThread $thread 更新対象の質問スレッドモデル
     *
     * @return RedirectResponse 質問詳細画面へのリダイレクトレスポンス
     */
    public function update(UpdateQaThreadRequest $request, QaThread $thread): RedirectResponse
    {
        $thread->updateWithTransaction($request->validated());

        return redirect()->route('qa-board.show', $thread)->with('success', '質問を更新しました。');
    }

    /**
     * 質問スレッドの詳細画面を表示する。
     *
     * @param QaThread $thread 質問スレッドモデル
     *
     * @return View 質問詳細画面のビュー
     */
    public function show(QaThread $thread): View
    {
        $thread->load(['user', 'certification', 'replies.user']);

        return view('qa-thread.show', compact('thread'));
    }

    /**
     * 指定された質問スレッドの削除処理を行う。
     *
     * 管理者（Admin）には強制削除特権を付与し、受講生には回答付きスレッドの削除制限を課します。
     * N+1問題を徹底的に回避しつつ、削除後の関連通知の自動クリーンアップまでを一気通貫で実行します。
     *
     * @param Request $request HTTPリクエストオブジェクト
     * @param QaThread $thread ルートモデルバインディングされた削除対象の質問スレッド
     *
     * @return RedirectResponse 質問一覧画面（qa-board.index）へのリダイレクトレスポンス
     */
    public function destroy(Request $request, QaThread $thread): RedirectResponse
    {
        // 小文字の user() に修正してメソッド未定義エラーを完全に防ぎます
        /** @var User $currentUser */
        $currentUser = $request->user();

        // 【認可ロジック】管理者はすべての制限をバイパスして削除可能
        if ($currentUser->role !== UserRole::Admin->value) {

            // 【N+1対策】メモリ上にすでに replies リレーションがロードされている場合はそれを利用、
            // ロードされていない場合のみ最小限のクエリで高速な存在確認を行います。
            $hasReplies = $thread->relationLoaded('replies')
                ? $thread->replies->isNotEmpty()
                : $thread->replies()->exists();

            if ($hasReplies) {
                // 制限に引っかかった場合は、削除をブロックして前の画面へエラーメッセージ付きで戻す
                return redirect()->back()->with('error', '回答がついているスレッドは削除できません。');
            }
        }

        // 重複していた findOrFail を廃止し、バインディングされたモデルを直接安全に削除
        $thread->delete();

        // 整合性を保つため、削除されたスレッドに関連する通知レコードも連動して自動クリーンアップ
        DatabaseNotification::where('data->url', "/qa-board/{$thread->id}")->delete();

        // 存在しない詳細画面ではなく、正しい一覧画面（qa-board.index）へリダイレクトさせます
        return redirect()->route('qa-board.index')
            ->with('status', '質問スレッドを削除しました。');
    }

    /**
     * 質問スレッドを「解決済」にするリクエストを受け付ける。
     *
     * @param QaThread $thread 質問スレッドモデル
     *
     * @return RedirectResponse 直前の画面へのリダイレクトレスポンス
     */
    public function resolve(QaThread $thread): RedirectResponse
    {
        $thread->markAsResolved();

        return back()->with('success', '質問を解決済みにしました。');
    }

    /**
     * 質問スレッドを「未解決」に戻すリクエストを受け付ける。
     *
     * @param QaThread $thread 質問スレッドモデル
     *
     * @return RedirectResponse 直前の画面へのリダイレクトレスポンス
     */
    public function unresolve(QaThread $thread): RedirectResponse
    {
        $thread->markAsUnresolved();

        return back()->with('success', '質問を未解決に戻しました。');
    }

    /**
     * 管理者専用の横断モデレーション用質問一覧画面を表示する。
     *
     * @param Request $request リクエストオブジェクト
     *
     * @return View 管理者用質問一覧画面のビュー
     */
    public function indexAsAdmin(Request $request): View
    {
        $filters = $request->only(['status', 'certification_id', 'keyword']);

        $threads = $this->qaThreadQueryService->getPaginatedThreads($filters, 10);

        $certifications = Certification::all();
        $publishedStatus = CertificationStatus::Published;

        return view('qa-thread.index', compact('threads', 'certifications', 'filters', 'publishedStatus'));
    }

    /**
     * 管理者による質問スレッドの強制モデレーション削除リクエストを受け付ける。
     *
     * @param QaThread $thread 削除対象の質問スレッドモデル
     *
     * @return RedirectResponse 管理者用質問一覧画面へのリダイレクトレスポンス
     */
    public function destroyAsAdmin(QaThread $thread): RedirectResponse
    {
        $thread->deleteWithTransaction();

        return redirect()->route('admin.qa-board.index')->with('success', '質問をモデレーション削除しました。');
    }

    /**
     * 管理者専用の質問スレッド詳細（モデレーション）画面を表示する。
     * スレッド本体、投稿ユーザー、関連する回答一覧を一括で取得してビューに返却する。
     *
     * @param QaThread $thread 該当する質問スレッドのモデルインスタンス
     *
     * @return View 管理者用質問詳細画面のビュー
     */
    public function showAsAdmin(QaThread $thread): View
    {
        $thread->load(['user', 'certification', 'replies.user']);

        return view('qa-thread.show', compact('thread'));
    }
}
