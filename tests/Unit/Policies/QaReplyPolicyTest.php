<?php

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use App\Policies\QaReplyPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaReplyPolicy に関する受講生・コーチ・管理者別の回答操作権限を検証する単体テスト。
 */
class QaReplyPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 回答の新規投稿（create）において、受講生とコーチは許可され、管理者は拒否されることを検証する。
     *
     * @return void
     */
    public function test_create_permission_allows_student_and_coach_but_denies_admin(): void
    {
        /** @var User $student */
        $student = User::factory()->make(['role' => UserRole::Student]);
        /** @var User $coach */
        $coach = User::factory()->make(['role' => UserRole::Coach]);
        /** @var User $admin */
        $admin = User::factory()->make(['role' => UserRole::Admin]);
        /** @var QaThread $thread */
        $thread = QaThread::factory()->make();

        $policy = new QaReplyPolicy();

        $this->assertTrue($policy->create($student, $thread));
        $this->assertTrue($policy->create($coach, $thread));
        $this->assertFalse($policy->create($admin, $thread));
    }

    /**
     * 回答の更新（update）において、回答を投稿した本人（受講生やコーチ）のみに許可されることを検証する。
     *
     * @return void
     */
    public function test_update_permission_allows_author_only(): void
    {
        /** @var User $author */
        $author = User::factory()->create(['role' => UserRole::Coach]);
        /** @var User $otherUser */
        $otherStudent = User::factory()->create(['role' => UserRole::Student]);

        /** @var QaReply $reply */
        $reply = QaReply::factory()->create(['user_id' => $author->id]);

        $policy = new QaReplyPolicy();

        $this->assertTrue($policy->update($author, $reply));
        $this->assertFalse($policy->update($otherStudent, $reply));
    }

    /**
     * 回答の削除（delete）において、投稿者本人、または管理者（モデレーション目的）にのみ許可されることを検証する。
     *
     * @return void
     */
    public function test_delete_permission_allows_author_and_admin_but_denies_others(): void
    {
        /** @var User $author */
        $author = User::factory()->create(['role' => UserRole::Student]);
        /** @var User $admin */
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        /** @var User $otherUser */
        $otherUser = User::factory()->create(['role' => UserRole::Coach]);

        /** @var QaReply $reply */
        $reply = QaReply::factory()->create(['user_id' => $author->id]);

        $policy = new QaReplyPolicy();

        $this->assertTrue($policy->delete($author, $reply));
        $this->assertTrue($policy->delete($admin, $reply));
        $this->assertFalse($policy->delete($otherUser, $reply));
    }
}
