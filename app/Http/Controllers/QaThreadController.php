<?php

namespace App\Http\Controllers;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * 質問掲示板（Q&A）の質問スレッドに関するリクエスト受付とレスポンス返却を制御するコントローラー。
 */
class QaThreadController extends Controller
{
    /**
     * 質問一覧画面を表示する。
     * 各種検索フィルタ（解決状態、資格ID、キーワード）を安全に引き受けてビューに渡す。
     *
     * @param Request $request リクエストオブジェクト
     * @return View 質問一覧画面のビュー
     */
    public function index(Request $request): View
    {
        $filters = $request->only(['status', 'certification_id', 'keyword']);

        // when内のクロージャの第2引数は型宣言を緩めるか省くのがLaravelの標準仕様です
        $threads = QaThread::with(['user', 'certification'])
            ->withCount('replies')
            ->when($filters['status'] ?? null, function ($query, $status): void {
                $query->where('status', $status);
            })
            ->when($filters['certification_id'] ?? null, function ($query, $certId): void {
                $query->where('certification_id', $certId);
            })
            ->when($filters['keyword'] ?? null, function ($query, $keyword): void {
                $query->where('body', 'like', "%{$keyword}%");
            })
            ->latest()
            ->paginate(10);

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
     * @param Request $request リクエストオブジェクト
     * @return RedirectResponse 質問詳細画面へのリダイレクトレスポンス
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'certification_id' => 'required|exists:certifications,id',
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
        ]);

        $thread = QaThread::createWithTransaction($request->user(), $data);

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
     * @param Request $request 入力データを含むリクエストオブジェクト
     * @param QaThread $thread 更新対象の質問スレッドモデル
     * @return RedirectResponse 質問詳細画面へのリダイレクトレスポンス
     */
    public function update(Request $request, QaThread $thread): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:5000',
        ]);

        $thread->updateWithTransaction($data);

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
     * 受講生用画面と全く同じパーツ構成に対応するため、必要な変数をすべて網羅して返却する。
     *
     * @param Request $request リクエストオブジェクト
     * @return View 管理者用質問一覧画面のビュー
     */
    public function indexAsAdmin(Request $request): View
    {
        $filters = $request->only(['status', 'certification_id', 'keyword']);

        $threads = QaThread::with(['user', 'certification'])
            ->withCount('replies')
            ->when($filters['status'] ?? null, function ($query, $status): void {
                $query->where('status', $status);
            })
            ->when($filters['certification_id'] ?? null, function ($query, $certId): void {
                $query->where('certification_id', $certId);
            })
            ->when($filters['keyword'] ?? null, function ($query, $keyword): void {
                $query->where('body', 'like', "%{$keyword}%");
            })
            ->latest()
            ->paginate(20);

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
