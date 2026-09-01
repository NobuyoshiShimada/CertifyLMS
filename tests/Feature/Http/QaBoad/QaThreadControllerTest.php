<?php

declare(strict_types=1);

namespace Tests\Feature\Http\QaBoard;

use App\Enums\CertificationStatus;
use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThreadController に関するリクエスト受付、レスポンス、画面遷移、ステータス変更を検証する機能テスト。
 */
class QaThreadControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 認証済みの受講生が質問一覧画面に正常にアクセスでき、必要な変数が引き渡されていることを検証する。
     *
     * @return void
     */
    public function test_index_screen_can_be_rendered_for_authenticated_user(): void
    {
        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);

        $response = $this->actingAs($student)->get(route('qa-board.index'));

        $response->assertStatus(200);
        $response->assertViewIs('qa-thread.index');
        $response->assertViewHasAll(['threads', 'certifications', 'filters', 'publishedStatus']);
    }

    /**
     * 認可ルールに基づき、受講生が正常に入力値（Form Request通過データ）を送信して新規質問を投稿できることを検証する。
     *
     * @return void
     */
    public function test_store_action_saves_thread_and_redirects_to_show_screen(): void
    {
        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);
        /** @var Certification $certification */
        $certification = Certification::factory()->create(['status' => CertificationStatus::Published]);

        $postData = [
            'certification_id' => $certification->id,
            'title' => 'テスト質問タイトル',
            'body' => 'テスト用の具体的な本文テキストです。',
        ];

        $response = $this->actingAs($student)->post(route('qa-board.store'), $postData);

        // 新しく作られたスレッドをデータベースから取得
        $thread = QaThread::first();

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '質問を投稿しました。');

        $this->assertDatabaseHas('qa_threads', [
            'title' => 'テスト質問タイトル',
            'user_id' => $student->id,
        ]);
    }

    /**
     * 投稿者本人がスレッド詳細画面から解決済アクションを実行した際、ステータスが更新されて元の画面に戻ることを検証する。
     *
     * @return void
     */
    public function test_resolve_action_updates_status_and_redirects_back(): void
    {
        /** @var User $student */
        $student = User::factory()->create(['role' => UserRole::Student]);
        /** @var QaThread $thread */
        $thread = QaThread::factory()->create([
            'user_id' => $student->id,
            'status' => QaThreadStatus::Unresolved,
        ]);

        $response = $this->actingAs($student)
            ->from(route('qa-board.show', $thread))
            ->post(route('qa-board.resolve', $thread));

        $response->assertStatus(302);
        $response->assertRedirect(route('qa-board.show', $thread));
        $response->assertSessionHas('success', '質問を解決済みにしました。');

        $this->assertEquals(QaThreadStatus::Resolved, $thread->fresh()->status);
    }

    /**
     * 管理者がアクセスした際、管理者専用の横断モデレーション用質問一覧画面が表示されることを検証する。
     *
     * @return void
     */
    public function test_index_as_admin_screen_can_be_rendered_for_admin_user(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->get(route('admin.qa-board.index'));

        $response->assertStatus(200);
        $response->assertViewIs('qa-thread.index');
        $response->assertViewHasAll(['threads', 'certifications', 'filters', 'publishedStatus']);
    }
}
