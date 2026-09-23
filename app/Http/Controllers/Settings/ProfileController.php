<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAvatarRequest;
use App\Http\Requests\Settings\UpdateProfileRequest;
use App\UseCases\Settings\DestroyAvatarAction;
use App\UseCases\Settings\StoreAvatarAction;
use App\UseCases\Settings\UpdateProfileAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * 全ロール共通の設定画面(プロフィール / アバター / パスワード)。対象は常にログイン中の本人。
 *
 * タブ状態は ?tab= で URL に持たせ、各操作の成功・失敗とも該当タブへ戻す。
 * 修了済受講生も使えるよう、学習有効性ガード(active-learning)は掛けない。
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('settings.profile', ['user' => $request->user()]);
    }

    public function update(UpdateProfileRequest $request, UpdateProfileAction $action): RedirectResponse
    {
        $action($request->user(), $request->validated());

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'プロフィールを更新しました。');
    }

    public function storeAvatar(StoreAvatarRequest $request, StoreAvatarAction $action): RedirectResponse
    {
        $action($request->user(), $request->file('avatar'));

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'アイコン画像を更新しました。');
    }

    public function destroyAvatar(Request $request, DestroyAvatarAction $action): RedirectResponse
    {
        $action($request->user());

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'profile'])
            ->with('success', 'アイコン画像を削除しました。');
    }

    /**
     * 現在のパスワード照合 / 8 文字以上 / 確認一致 は Fortify 既存の UpdateUserPassword に委ねる。
     */
    public function updatePassword(Request $request, UpdatesUserPasswords $updater): RedirectResponse
    {
        try {
            $updater->update($request->user(), $request->only(['current_password', 'password', 'password_confirmation']));
        } catch (ValidationException $e) {
            throw $e->redirectTo(route('settings.profile.edit', ['tab' => 'password']));
        }

        return redirect()
            ->route('settings.profile.edit', ['tab' => 'password'])
            ->with('success', 'パスワードを変更しました。');
    }
}
