<?php

namespace App\Http\Controllers;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Http\Requests\QaBoad\StoreQaReplyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
/**
 * 質問スレッドに対する回答（リプライ）の投稿・削除リクエストの受付とレスポンスを制御するコントローラー。
 */
class QaReplyController extends Controller
{
    /**
     * 新しい回答を登録するリクエストを受け付ける。
     *
     * @param StoreQaReplyRequest $request フォームリクエストオブジェクト
     * @param QaThread $thread 対象の質問スレッドモデル
     * @return RedirectResponse 直前の詳細画面へのリダイレクトレスポンス
     */
    public function store(StoreQaReplyRequest $request, QaThread $thread): RedirectResponse
    {
        QaReply::createWithTransaction($thread, $request->user(), $request->input('body'));

        return back()->with('success', '回答を投稿しました。');
    }

    /**
     * 指定された回答の編集画面を表示する。
     *
     * @param QaThread $thread 親の質問スレッドモデル
     * @param QaReply $reply 編集対象の回答モデル
     * @return \Illuminate\View\View 回答編集画面のビュー
     */
    public function edit(QaThread $thread, QaReply $reply): View
    {
        return view('qa-thread.reply-edit', compact('thread', 'reply'));
    }

    /**
     * 回答の変更内容を保存するリクエストを受け付ける。
     *
     * @param StoreQaReplyRequest $request フォームリクエストオブジェクト
     * @param QaThread $thread 親の質問スレッドモデル
     * @param QaReply $reply 更新対象の回答モデル
     * @return \Illuminate\Http\RedirectResponse 質問詳細画面へのリダイレクトレスポンス
     */
    public function update(StoreQaReplyRequest $request, QaThread $thread, QaReply $reply): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $reply->updateWithTransaction($data);

        return redirect()->route('qa-board.show', $thread)->with('success', '回答を更新しました。');
    }

    /**
     * 回答データを削除するリクエストを受け付ける。
     *
     * @param QaThread $thread 質問スレッドモデル
     * @param QaReply $reply 削除対象の回答モデル
     * @return RedirectResponse 直前の詳細画面へのリダイレクトレスポンス
     */
    public function destroy(QaThread $thread, QaReply $reply): RedirectResponse
    {
        $reply->deleteWithTransaction();

        return back()->with('success', '回答を削除しました。');
    }

    /**
     * 管理者による回答データの強制モデレーション削除リクエストを受け付ける。
     * 削除処理はモデル内のトランザクションメソッドを介して安全に実行される。
     *
     * @param QaThread $thread 該当する質問スレッドのモデルインスタンス
     * @param QaReply $reply 削除対象となる回答のモデルインスタンス
     * @return \Illuminate\Http\RedirectResponse 直前の詳細画面へのリダイレクトレスポンス
     */
    public function destroyAsAdmin(QaThread $thread, QaReply $reply): \Illuminate\Http\RedirectResponse
    {
        $reply->deleteWithTransaction();

        return back()->with('success', '回答をモデレーション削除しました。');
    }
}
