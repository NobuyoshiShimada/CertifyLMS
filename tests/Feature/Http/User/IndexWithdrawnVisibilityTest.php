<?php

declare(strict_types=1);

namespace Tests\Feature\Http\User;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\UserWithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-04 回帰テスト: 退会済(論理削除済)ユーザーは状態フィルタ「退会済」のときだけ一覧に含まれ、
 * フィルタなし / 在籍系フィルタでは除外されること、詳細画面は引き続き開けることを検証する。
 */
class IndexWithdrawnVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $withdrawn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->withdrawn = User::factory()->student()->inProgress()->create();
        app(UserWithdrawalService::class)->withdraw($this->withdrawn);
        $this->withdrawn->refresh();
    }

    /**
     * @return array<int, string>
     */
    private function listedIds(?string $status): array
    {
        $query = $status === null ? [] : ['status' => $status];

        return $this->actingAs($this->admin)
            ->get(route('admin.users.index', $query))
            ->assertOk()
            ->viewData('users')
            ->pluck('id')
            ->all();
    }

    public function test_withdrawn_user_is_soft_deleted(): void
    {
        $this->assertSame(UserStatus::Withdrawn, $this->withdrawn->status);
        $this->assertTrue($this->withdrawn->trashed());
    }

    public function test_no_filter_excludes_withdrawn_user(): void
    {
        $active = User::factory()->student()->inProgress()->create();

        $ids = $this->listedIds(null);

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($this->withdrawn->id, $ids);
    }

    /**
     * @dataProvider activeStatusProvider
     */
    public function test_active_status_filters_exclude_soft_deleted_users(UserStatus $status): void
    {
        $active = User::factory()->student()->create(['status' => $status->value]);
        // 状態は在籍系のまま論理削除されたユーザー(不整合データ)も在籍系フィルタには出さない
        $softDeletedSameStatus = User::factory()->student()->create(['status' => $status->value]);
        $softDeletedSameStatus->delete();

        $ids = $this->listedIds($status->value);

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($softDeletedSameStatus->id, $ids);
        $this->assertNotContains($this->withdrawn->id, $ids);
    }

    /**
     * @return array<string, array{UserStatus}>
     */
    public static function activeStatusProvider(): array
    {
        return ['受講中' => [UserStatus::InProgress], '卒業' => [UserStatus::Graduated]];
    }

    public function test_withdrawn_filter_includes_withdrawn_user_only(): void
    {
        $active = User::factory()->student()->inProgress()->create();

        $ids = $this->listedIds(UserStatus::Withdrawn->value);

        $this->assertContains($this->withdrawn->id, $ids);
        $this->assertNotContains($active->id, $ids);
    }

    public function test_withdrawn_user_detail_is_still_accessible(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $this->withdrawn))
            ->assertOk();
    }
}
