<?php

namespace Tests\Unit\Models;

use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReply モデルのビジネスロジックおよびデータベーストランザクション処理を検証する単体テスト。
 */
class QaReplyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * データベーストランザクションを介して、質問スレッドに対する回答データが正しい親子関係で保存されることを検証する。
     *
     * @return void
     */
    public function test_create_with_transaction_saves_reply_correctly(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        /** @var QaThread $thread */
        $thread = QaThread::factory()->create();

        $body = 'これはテスト用の回答本文テキストです。';

        // モデルメソッドの実行
        $reply = QaReply::createWithTransaction($thread, $user, $body);

        // 戻り値とデータベースの永続化状態を検証
        $this->assertInstanceOf(QaReply::class, $reply);
        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'qa_thread_id' => $thread->id,
            'user_id' => $user->id,
            'body' => $body,
        ]);
    }

    /**
     * updateWithTransaction メソッドによって、回答本文の変更がデータベーストランザクション保護下で安全に更新されることを検証する。
     *
     * @return void
     */
    public function test_update_with_transaction_modifies_reply_body_correctly(): void
    {
        /** @var QaReply $reply */
        $reply = QaReply::factory()->create(['body' => '元の回答本文']);

        $updateData = ['body' => '修正後の新しい回答本文'];

        // モデルメソッドの実行（更新）
        $reply->updateWithTransaction($updateData);

        // データベースの値が上書きされているか検証
        $this->assertDatabaseHas('qa_replies', [
            'id' => $reply->id,
            'body' => '修正後の新しい回答本文',
        ]);
    }

    /**
     * deleteWithTransaction メソッドにより、該当の回答データがデータベースから物理削除されることを検証する。
     *
     * @return void
     */
    public function test_delete_with_transaction_removes_reply_from_database(): void
    {
        /** @var QaReply $reply */
        $reply = QaReply::factory()->create();

        // データベースにデータが存在することを確認
        $this->assertDatabaseHas('qa_replies', ['id' => $reply->id]);

        // モデルメソッドの実行（削除）
        $reply->deleteWithTransaction();

        // データベースから完全に消えていることを検証
        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }
}
