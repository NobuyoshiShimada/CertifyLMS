<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\MeetingReminderNotification;
use Tests\TestCase;

/**
 * Class MeetingReminderNotificationTest
 *
 * 面談リマインダー通知のデータ構造・配信設定を検証するUnitテスト。
 */
class MeetingReminderNotificationTest extends TestCase
{
    public function test_it_configures_database_and_mail_channels_with_correct_payload(): void
    {
        $mockUser = \Mockery::mock(User::class);
        $mockUser->shouldReceive('getAttribute')->with('name')->andReturn('受講生太郎');

        $inputData = [
            'title' => '面談リマインダー',
            'message' => '明日 2026/09/15 15:00 に面談の予定があります。',
            'url' => 'http://localhost/meetings/abc123',
        ];

        $notification = new MeetingReminderNotification($inputData);

        // 配信先アサーション
        $this->assertSame(['database', 'mail'], $notification->via($mockUser));

        // DBペイロードアサーション
        $dbData = $notification->toDatabase($mockUser);
        $this->assertSame('meeting_reminder', $dbData['notification_type']);
        $this->assertSame('面談リマインダー', $dbData['title']);
        $this->assertSame('明日 2026/09/15 15:00 に面談の予定があります。', $dbData['message']);
        $this->assertSame('http://localhost/meetings/abc123', $dbData['url']);

        // メールオブジェクトアサーション
        $mailData = $notification->toMail($mockUser);
        $this->assertSame('【LMS】面談リマインダー', $mailData->subject);
    }
}
