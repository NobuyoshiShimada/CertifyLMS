<?php

namespace Tests\Feature\Http\QaBoard;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReplyController に関する回答の新規登録、更新、通常削除、管理者モデレーション削除を検証する機能テスト。
 */
class QaReplyControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * コーチが正常に入力値を送信して、質問スレッドに対する回答を投稿できることを検証する。
     *
     * @return void
     */
    public function test_store_action_saves_reply_and_redirects_back(): void
    {
        /** @var User $coach */
        $coach = User::factory()->create(['role' => UserRole::Coach]);
        /** @var QaThread $thread */
        $thread = QaThread::factory()->create();

        $replyData = ['body' => 'コーチからの丁寧な回答テキストです。'];

        $response = $this->actingAs($coach)
            ->from(route('qa-board.show', $thread))
            ->post(route('qa-board.replies.store', $thread), $replyData);

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '回答を投稿しました。');

        $this->assertDatabaseHas('qa_replies', [
            'qa_thread_id' => $thread->id,
            'user_id'      => $coach->id,
            'body'         => 'コーチからの丁寧な回答テキストです。',
        ]);
    }

    /**
     * 回答の投稿者本人が回答内容の変更を送信した際、データが更新されて詳細画面へリダイレクトされることを検証する。
     *
     * @return void
     */
    public function test_update_action_modifies_reply_body_and_redirects_to_show_screen(): void
    {
        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);
        /** @var QaReply $reply */
        $reply = QaReply::factory()->create([
            'user_id' => $student->id,
            'body'    => '修正前の回答本文',
        ]);
        $thread = $reply->qaThread;

        $updateData = ['body' => '新しく修正された回答本文テキスト。'];

        $response = $this->actingAs($student)
            ->patch(route('qa-board.replies.update', ['thread' => $thread->id, 'reply' => $reply->id]), $updateData);

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '回答を更新しました。');

        $this->assertEquals('新しく修正された回答本文テキスト。', $reply->fresh()->body);
    }

    /**
     * 管理者がモデレーション用のエンドポイントを叩くことで、他人の不適切な回答を物理削除できることを検証する。
     *
     * @return void
     */
    public function test_destroy_as_admin_action_removes_reply_and_redirects_back(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        /** @var QaReply $reply */
        $reply = QaReply::factory()->create();
        $thread = $reply->qaThread;

        $response = $this->actingAs($admin)
            ->from(route('admin.qa-board.show', $thread))
            ->delete(route('admin.qa-board.replies.destroy', ['thread' => $thread->id, 'reply' => $reply->id]));

        $response->assertStatus(302);
        $response->assertRedirect(route('admin.qa-board.show', $thread));
        $response->assertSessionHas('success', '回答をモデレーション削除しました。');

        $this->assertDatabaseMissing('qa_replies', ['id' => $reply->id]);
    }
}
