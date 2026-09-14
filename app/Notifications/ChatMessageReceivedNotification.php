<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Class ChatMessageReceivedNotification
 *
 * 業務イベント：チャットルームへの新着メッセージ投稿を、当事者（自分以外の参加者）へ通知するクラス。
 */
class ChatMessageReceivedNotification extends Notification
{
    use Queueable;

    /**
     * @var array{title: string, message: string, url: string}
     */
    protected array $messageData;

    /**
     * @param array{title: string, message: string, url: string} $messageData
     */
    public function __construct(array $messageData)
    {
        $this->messageData = $messageData;
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array{notification_type: string, title: string, message: string, url: string}
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'notification_type' => 'chat_message_received',
            'title' => $this->messageData['title'],
            'message' => $this->messageData['message'],
            'url' => $this->messageData['url'],
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【LMS】'.$this->messageData['title'])
            ->greeting("{$notifiable->name} 様")
            ->line($this->messageData['message'])
            ->action('チャットを確認する', url($this->messageData['url']))
            ->line('ご確認のほど、よろしくお願いいたします。');
    }
}
