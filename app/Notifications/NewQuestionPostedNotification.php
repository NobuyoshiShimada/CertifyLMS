<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Class NewQuestionPostedNotification
 *
 * 業務イベント：受講生による新規質問投稿を、当事者であるコーチへ通知するクラス。
 * アプリ内データベースへの記録と、即時メール配信を同時に制御します。
 */
class NewQuestionPostedNotification extends Notification
{
    use Queueable;

    protected array $questionData;

    /**
     * NewQuestionPostedNotification constructor.
     *
     * @param array{title: string, message: string, url: string} $questionData 質問のタイトル、通知文面、遷移先URLを含む配列
     */
    public function __construct(array $questionData)
    {
        $this->questionData = $questionData;
    }

    /**
     * 通知チャンネルの定義
     *
     * @param mixed $notifiable 通知を受信する対象オブジェクト（Userモデル等）
     *
     * @return array<int, string> 使用する通知チャンネル（databaseおよびmail）
     */
    public function via(mixed $notifiable): array
    {
        // アプリ内通知とメールの「即時同時配信」を実現
        return ['database', 'mail'];
    }

    /**
     * notifications テーブルの `data` カラムに保存されるJSON構造の定義
     *
     * @param mixed $notifiable 通知を受信する対象オブジェクト
     *
     * @return array{notification_type: string, title: string, message: string, url: string} DBに格納するデータ配列
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'notification_type' => 'qa_reply_received',
            'title' => '【新着質問】'.$this->questionData['title'],
            'message' => $this->questionData['message'],
            'url' => $this->questionData['url'],
        ];
    }

    /**
     * 送信される通知メールのテキストおよび構造の定義
     *
     * @param mixed $notifiable 通知を受信する対象オブジェクト
     *
     * @return MailMessage 生成されたメールメッセージオブジェクト
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【LMS新着】受講生から新しい質問が投稿されました')
            ->greeting("{$notifiable->name} 様")
            ->line($this->questionData['message'])
            ->action('質問スレッドを確認する', url($this->questionData['url']))
            ->line('ご確認のほど、よろしくお願いいたします。');
    }
}
