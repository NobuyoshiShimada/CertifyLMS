<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\QaThread;
use App\Models\User;

/**
 * 質問スレッド（QaThread）に関する操作権限（認可）を判定するポリシー。
 */
class QaThreadPolicy
{
    /**
     * ユーザーが新しい質問スレッドを投稿できるかどうかを判定する。
     * 受講生（Student）のみ投稿を許可する。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @return bool 投稿を許可する場合は true、それ以外は false
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /**
     * ユーザーが指定された質問スレッドの情報を更新（編集）できるかどうかを判定する。
     * 質問を投稿した本人（受講生）のみ許可する。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @param QaThread $qaThread 対象の質問スレッドインスタンス
     * @return bool 編集を許可する場合は true、それ以外は false
     */
    public function update(User $user, QaThread $qaThread): bool
    {
        return $user->id === $qaThread->user_id;
    }

    /**
     * ユーザーが指定された質問スレッドを削除できるかどうかを判定する。
     * 質問を投稿した本人、または管理者（Admin）によるモデレーション削除を許可する。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @param QaThread $qaThread 対象の質問スレッドインスタンス
     * @return bool 削除を許可する場合は true、それ以外は false
     */
    public function delete(User $user, QaThread $qaThread): bool
    {
        return $user->id === $qaThread->user_id || $user->role === UserRole::Admin;
    }

    /**
     * ユーザーが指定された質問スレッドを「解決済」に変更できるかどうかを判定する。
     * 質問を投稿した本人（受講生）のみ許可する。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @param QaThread $qaThread 対象の質問スレッドインスタンス
     * @return bool 解決済への変更を許可する場合は true、それ以外は false
     */
    public function resolve(User $user, QaThread $qaThread): bool
    {
        return $user->id === $qaThread->user_id;
    }

    /**
     * ユーザーが指定された質問スレッドを「未解決」に戻せるかどうかを判定する。
     * 質問を投稿した本人（受講生）のみ許可する。
     *
     * @param User $user ログイン中のユーザーインスタンス
     * @param QaThread $qaThread 対象の質問スレッドインスタンス
     * @return bool 未解決への変更を許可する場合は true、それ以外は false
     */
    public function unresolve(User $user, QaThread $qaThread): bool
    {
        return $user->id === $qaThread->user_id;
    }
}
