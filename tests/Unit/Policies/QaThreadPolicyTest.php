<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\QaThreadPolicy;
use Tests\TestCase;

/**
 * QaThreadPolicy に関する受講生・コーチ別の権限認可ルールを検証する単体テスト。
 */
class QaThreadPolicyTest extends TestCase
{
    /**
     * 質問の新規作成（create）において、受講生は許可され、コーチは拒否されることを検証する。
     *
     * @return void
     */
    public function test_create_permission_allows_student_and_denies_coach(): void
    {
        /** @var User $student */
        $student = User::factory()->make(['role' => UserRole::Student]);
        /** @var User $coach */
        $coach = User::factory()->make(['role' => UserRole::Coach]);

        $policy = new QaThreadPolicy;

        $this->assertTrue($policy->create($student));
        $this->assertFalse($policy->create($coach));
    }
}
