<?php

declare(strict_types=1);

namespace App\Exceptions\MeetingQuota;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 学習中(受講中)でない受講生が追加面談を購入しようとした場合の例外(403)。
 *
 * HTML 経由では前画面へ戻してフラッシュエラーを表示し、JSON 経由では 403 を返す。
 */
final class PurchaseNotAllowedException extends AccessDeniedHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('受講中のユーザーのみ追加面談を購入できます。', $previous);
    }

    public function render(Request $request): Response|RedirectResponse|false
    {
        if ($request->expectsJson()) {
            return false;
        }

        return redirect()->back()->with('error', $this->getMessage());
    }
}
