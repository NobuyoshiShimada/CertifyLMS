<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Tests\TestCase;

/**
 * Class AdminAnnouncementNotificationTest
 *
 * 管理者お知らせ配信通知のデータ構造・配信設定を検証するUnitテスト。
 */
class AdminAnnouncementNotificationTest extends TestCase
{
    public function test_it_configures_database_and_mail_channels_with_correct_payload(): void
    {
        $mockUser = \Mockery::mock(User::class);
        $mockUser->shouldReceive('getAttribute')->with('name')->andReturn('受講生太郎');

        $inputData = [
            'title' => 'システムメンテナンスのお知らせ',
            'body' => 'サーバーのアップデート作業を行います。',
        ];

        $notification = new AdminAnnouncementNotification($inputData);

        // 配信先アサーション
        $this->assertSame(['database', 'mail'], $notification->via($mockUser));

        // DBペイロードアサーション
        $dbData = $notification->toDatabase($mockUser);
        $this->assertSame('admin_announcement', $dbData['notification_type']);
        $this->assertSame('システムメンテナンスのお知らせ', $dbData['title']);
        $this->assertSame('サーバーのアップデート作業を行います。', $dbData['body']);
        $this->assertSame('サーバーのアップデート作業を行います。', $dbData['message']);

        // メールオブジェクトアサーション
        $mailData = $notification->toMail($mockUser);
        $this->assertSame('【LMS】システムメンテナンスのお知らせ', $mailData->subject);
    }
}
