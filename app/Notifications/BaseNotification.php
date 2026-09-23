<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * 業務通知(チャット / Q&A / 面談 / お知らせ)の共通基底。
 *
 * ShouldQueue により送信は発火元リクエストから切り離され、受信者 1 人 × チャネルごとの独立したジョブとして
 * worker が処理する(一斉配信でもリクエストはブロックされず、1 件の失敗が他の配信へ波及しない)。
 * 一時的な失敗は $tries 回まで backoff() の段階的な待機を挟んで自動リトライし、上限超過は failed_jobs に記録される。
 * トランザクション内で発火した場合も、キュー接続の after_commit により commit 後にのみ投入される。
 */
abstract class BaseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * 最大試行回数(初回 + リトライ)。
     */
    public int $tries = 3;

    /**
     * リトライ間の待機秒数(試行ごとに段階的に伸ばす)。
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }
}
