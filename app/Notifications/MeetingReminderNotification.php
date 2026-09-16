<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Class MeetingReminderNotification
 *
 * 業務イベント：予約済み面談の前日 / 開始1時間前を、当事者(受講生・コーチ)へ通知するクラス。
 */
class MeetingReminderNotification extends Notification
{
    use Queueable;

    /**
     * @var array{title: string, message: string, url: string}
     */
    protected array $meetingData;

    /**
     * @param array{title: string, message: string, url: string} $meetingData
     */
    public function __construct(array $meetingData)
    {
        $this->meetingData = $meetingData;
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
            'notification_type' => 'meeting_reminder',
            'title' => $this->meetingData['title'],
            'message' => $this->meetingData['message'],
            'url' => $this->meetingData['url'],
        ];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('【LMS】'.$this->meetingData['title'])
            ->greeting("{$notifiable->name} 様")
            ->line($this->meetingData['message'])
            ->action('面談詳細を確認する', url($this->meetingData['url']))
            ->line('ご確認のほど、よろしくお願いいたします。');
    }
}
