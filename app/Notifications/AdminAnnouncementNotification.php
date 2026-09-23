<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Class AdminAnnouncementNotification
 *
 * 業務イベント：管理者による受講生向け一斉お知らせ配信を、対象の受講生へ通知するクラス。
 * 通知詳細ページ(notifications.show)で本文全文を読む自己完結型の通知のため、遷移先 url は持たない。
 */
class AdminAnnouncementNotification extends BaseNotification
{
    /**
     * @var array{title: string, body: string}
     */
    protected array $announcementData;

    /**
     * @param array{title: string, body: string} $announcementData
     */
    public function __construct(array $announcementData)
    {
        $this->announcementData = $announcementData;
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array{notification_type: string, title: string, message: string, body: string}
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'notification_type' => 'admin_announcement',
            'title' => $this->announcementData['title'],
            'message' => $this->announcementData['body'],
            'body' => $this->announcementData['body'],
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【LMS】'.$this->announcementData['title'])
            ->greeting("{$notifiable->name} 様")
            ->line($this->announcementData['body']);
    }
}
