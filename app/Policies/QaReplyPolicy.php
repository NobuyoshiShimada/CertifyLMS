<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問への回答（QaReply）に関する操作権限（認可）を判定するポリシー。
 */
class QaReplyPolicy
{
    /**
     * ユーザーが指定された質問スレッドに対して回答を投稿できるかどうかを判定する。
     * 受講生（Student）およびコーチ（Coach）に許可し、管理者（Admin）は不許可とする。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @param QaThread $qaThread 回答対象の質問スレッドインスタンス
     *
     * @return bool 投稿を許可する場合は true、それ以外は false
     */
    public function create(User $user, QaThread $qaThread): bool
    {
        return $user->role === UserRole::Student || $user->role === UserRole::Coach;
    }

    /**
     * ユーザーが指定された回答を更新（編集）できるかどうかを判定する。
     * 回答を投稿した本人にのみ許可する。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @param QaReply $qaReply 対象の回答インスタンス
     *
     * @return bool 編集を許可する場合は true、それ以外は false
     */
    public function update(User $user, QaReply $qaReply): bool
    {
        return $user->id === $qaReply->user_id;
    }

    /**
     * ユーザーが指定された回答を削除できるかどうかを判定する。
     * 回答を投稿した本人、または管理者（Admin）によるモデレーション削除を許可する。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @param QaReply $qaReply 対象の回答インスタンス
     *
     * @return bool 削除を許可する場合は true、それ以外は false
     */
    public function delete(User $user, QaReply $reply): bool
    {
        return $user->id === $reply->user_id || $user->role === UserRole::Admin;
    }
}
