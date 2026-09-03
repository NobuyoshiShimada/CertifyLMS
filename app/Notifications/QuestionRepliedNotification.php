<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Class QuestionRepliedNotification
 *
 * 業務イベント：コーチによる質問への回答投稿を、当事者である受講生（質問投稿者）のデータベース通知基盤へ保存するクラス。
 */
class QuestionRepliedNotification extends Notification
{
    use Queueable;

    /**
     * 通知に必要な動的データ
     *
     * @var array{title: string, message: string, url: string}
     */
    protected array $replyData;

    /**
     * QuestionRepliedNotification constructor.
     *
     * @param array{title: string, message: string, url: string} $replyData 回答通知データ
     */
    public function __construct(array $replyData)
    {
        $this->replyData = $replyData;
    }

    /**
     * 通知チャンネルの定義
     *
     * @param mixed $notifiable 通知を受信する受講生
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * notifications テーブルの `data` カラムに保存されるJSON構造
     *
     * @param mixed $notifiable 通知を受信する受講生
     *
     * @return array{notification_type: string, title: string, message: string, url: string}
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'notification_type' => 'qa_reply_received', // 一覧やポップオーバーのアイコン分岐に完全適合
            'title' => '【質問回答】'.$this->replyData['title'],
            'message' => $this->replyData['message'],
            'url' => $this->replyData['url'], // 既読化・リダイレクト用の遷移先業務URL（/qa-board/{thread}）
        ];
    }
}
