<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Policies\DatabaseNotificationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Class DatabaseNotificationPolicyTest
 *
 * DatabaseNotificationPolicyの認可ロジック（403防御）を単体検証するUnitテスト。
 */
class DatabaseNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $otherUser;

    private DatabaseNotificationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        // 確実に異なる一意なID（ULID文字列）を付与してユーザーを生成
        $this->student = User::factory()->student()->create();
        $this->student->id = '01m1f2f1a75kpm5q1f55pea1st';
        $this->student->save();

        $this->otherUser = User::factory()->student()->create();
        $this->otherUser->id = '01m1f2f1a56fpsjgyv1gy59aco';
        $this->otherUser->save();

        $this->policy = new DatabaseNotificationPolicy;
    }

    /**
     * 通知の所有者本人である場合に、ポリシーがアクセスを許可（true）することを確認する。
     *
     * @return void
     */
    public function test_policy_allows_update_for_notification_owner(): void
    {
        $notification = DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\QuestionRepliedNotification',
            'notifiable_type' => get_class($this->student),
            'notifiable_id' => $this->student->id,
            'data' => ['title' => 'テスト通知'],
        ]);

        $this->assertTrue($this->policy->update($this->student, $notification));
    }

    /**
     * 他人の通知レコードに対して、ポリシーがアクセスを拒否（false）することを確認する。
     *
     * @return void
     */
    public function test_policy_denies_update_for_non_owner(): void
    {
        $notification = DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\QuestionRepliedNotification',
            'notifiable_type' => get_class($this->student),
            'notifiable_id' => $this->student->id,
            'data' => ['title' => 'テスト通知'],
        ]);

        // IDが明確に異なるため、確実に認可が拒否されます
        $this->assertFalse($this->policy->update($this->otherUser, $notification));
    }
}
