<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\NewQuestionPostedNotification;
use Tests\TestCase;

/**
 * Class NewQuestionPostedNotificationTest
 *
 * 受講生➔コーチへの新着質問投稿通知のデータ構造・配信設定を検証するUnitテスト。
 */
class NewQuestionPostedNotificationTest extends TestCase
{
    /**
     * データベースとメールの即時同時配信チャンネルが選択され、data構造が正しいか検証する。
     *
     * @return void
     */
    public function test_it_configures_database_and_mail_channels_with_correct_payload(): void
    {
        $mockUser = \Mockery::mock(User::class);
        $mockUser->shouldReceive('getAttribute')->with('name')->andReturn('コーチ山田');

        $inputData = [
            'title' => 'Dockerのマイグレーションエラー',
            'message' => '佐藤さんが新しい質問を投稿しました。',
            'url' => '/qa-board/5',
        ];

        $notification = new NewQuestionPostedNotification($inputData);

        // 配信先アサーション
        $this->assertSame(['database', 'mail'], $notification->via($mockUser));

        // DBペイロードアサーション
        $dbData = $notification->toDatabase($mockUser);
        $this->assertSame('qa_reply_received', $dbData['notification_type']);
        $this->assertSame('【新着質問】Dockerのマイグレーションエラー', $dbData['title']);
        $this->assertSame('/qa-board/5', $dbData['url']);

        // メールオブジェクトアサーション
        $mailData = $notification->toMail($mockUser);
        $this->assertSame('【LMS新着】受講生から新しい質問が投稿されました', $mailData->subject);
    }
}
