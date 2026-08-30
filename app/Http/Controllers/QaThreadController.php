<?php

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Services\QaThreadQueryService;
use App\Http\Requests\QaBoard\StoreQaThreadRequest;
use App\Http\Requests\QaBoard\UpdateQaThreadRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 質問掲示板（Q&A）の質問スレッドに関するリクエスト受付とレスポンス返却を制御するコントローラー。
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
     * 新しい質問スレッドを保存するリクエストを受け付ける。
     *
     * @param StoreQaThreadRequest $request フォームリクエストオブジェクト
     * @return RedirectResponse 質問詳細画面へのリダイレクトレスポンス
     */
    public function store(StoreQaThreadRequest $request): RedirectResponse
    {
        $thread = QaThread::createWithTransaction($request->user(), $request->validated());

        return redirect()->route('qa-board.show', $thread)->with('success', '質問を投稿しました。');
    }

/**
     * 指定された質問スレッドの編集画面を表示する。
     *
     * @param QaThread $thread 編集対象の質問スレッドモデル
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
     * @return View 質問詳細画面のビュー
     */
    public function show(QaThread $thread): View
    {
        $thread->load(['user', 'certification', 'replies.user']);

        return view('qa-thread.show', compact('thread'));
    }

    /**
     * 指定された質問スレッドデータを削除するリクエストを受け付ける（受講生・コーチ用）。
     *
     * @param QaThread $thread 削除対象の質問スレッドモデル
     * @return RedirectResponse 質問一覧画面へのリダイレクトレスポンス
     */
    public function destroy(QaThread $thread): RedirectResponse
    {
        $thread->deleteWithTransaction();

        return redirect()->route('qa-board.index')->with('success', '質問を削除しました。');
    }

    /**
     * 質問スレッドを「解決済」にするリクエストを受け付ける。
     *
     * @param QaThread $thread 質問スレッドモデル
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
     * @return View 管理者用質問詳細画面のビュー
     */
    public function showAsAdmin(QaThread $thread): View
    {
        $thread->load(['user', 'certification', 'replies.user']);

        return view('qa-thread.show', compact('thread'));
    }
}
