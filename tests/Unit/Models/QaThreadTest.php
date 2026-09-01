<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThread モデルのビジネスロジックおよびデータ操作を検証する単体テスト。
 */
class QaThreadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * トランザクションを介して質問スレッドが正しい初期値で保存されることを検証する。
     *
     * @return void
     */
    public function test_create_with_transaction_saves_correct_data(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        /** @var Certification $certification */
        $certification = Certification::factory()->create();

        $data = [
            'certification_id' => $certification->id,
            'title' => 'テストタイトル',
            'body' => 'テスト本文',
        ];

        $thread = QaThread::createWithTransaction($user, $data);

        $this->assertInstanceOf(QaThread::class, $thread);
        $this->assertDatabaseHas('qa_threads', [
            'id' => $thread->id,
            'status' => QaThreadStatus::Unresolved->value,
        ]);
    }

    /**
     * markAsResolved メソッドによってステータスが解決済になり解決日時が記録されることを検証する。
     *
     * @return void
     */
    public function test_mark_as_resolved_updates_status(): void
    {
        /** @var QaThread $thread */
        $thread = QaThread::factory()->create(['status' => QaThreadStatus::Unresolved]);

        $thread->markAsResolved();

        $this->assertEquals(QaThreadStatus::Resolved, $thread->status);
        $this->assertNotNull($thread->resolved_at);
    }
}
