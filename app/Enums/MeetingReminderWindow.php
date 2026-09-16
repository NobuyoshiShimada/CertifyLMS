<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 面談リマインダーの配信タイミング。
 *
 * `notifications:send-meeting-reminders --window=` の値と一致する。
 */
enum MeetingReminderWindow: string
{
    case Eve = 'eve';
    case OneHourBefore = 'one_hour_before';

    /**
     * 送信済みを記録する Meeting の該当カラム名。
     */
    public function sentAtColumn(): string
    {
        return match ($this) {
            self::Eve => 'eve_reminder_sent_at',
            self::OneHourBefore => 'one_hour_before_reminder_sent_at',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Eve => '前日',
            self::OneHourBefore => '1時間前',
        };
    }
}
