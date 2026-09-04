<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\QuestionRepliedNotification;
use Tests\TestCase;

/**
 * Class QuestionRepliedNotificationTest
 *
 * コーチ➔受講生への質問回答通知のデータ構造・配信設定を検証するUnitテスト。
 */
class QuestionRepliedNotificationTest extends TestCase
{
    /**
     * アプリ内通知データベースチャンネルのみが選択され、data構造が正しいか検証する。
     *
     * @return void
     */
    public function test_it_configures_database_only_channel_with_correct_payload(): void
    {
        $mockUser = \Mockery::mock(User::class);

        $inputData = [
            'title' => '回答が届きました',
            'message' => '山田コーチがあなたの質問に回答しました。',
            'url' => '/qa-board/12',
        ];

        $notification = new QuestionRepliedNotification($inputData);

        // MVPスコープ通り、データベースのみであることをアサート
        $this->assertSame(['database'], $notification->via($mockUser));

        // DBペイロードアサーション
        $dbData = $notification->toDatabase($mockUser);
        $this->assertSame('qa_reply_received', $dbData['notification_type']);
        $this->assertSame('【質問回答】回答が届きました', $dbData['title']);
        $this->assertSame('/qa-board/12', $dbData['url']);
    }
}
