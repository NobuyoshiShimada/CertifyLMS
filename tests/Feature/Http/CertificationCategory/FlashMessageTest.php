<?php

declare(strict_types=1);

namespace Tests\Feature\Http\CertificationCategory;

use App\Models\CertificationCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-07 回帰テスト: 資格分類マスタの追加 / 更新 / 削除それぞれの成功後、一覧画面に成功メッセージが表示されることを検証する。
 */
class FlashMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    public function test_destroy_redirects_to_index_and_shows_success_message(): void
    {
        $category = CertificationCategory::factory()->create();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->delete(route('admin.certification-categories.destroy', $category))
            ->assertOk()
            ->assertSee('分類を削除しました。');

        $this->assertDatabaseMissing('certification_categories', ['id' => $category->id]);
    }

    public function test_store_still_shows_success_message(): void
    {
        $this->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('admin.certification-categories.store'), [
                'name' => '新しい分類',
                'slug' => 'new-category',
                'sort_order' => 0,
            ])
            ->assertOk()
            ->assertSee('分類を追加しました。');
    }

    public function test_update_still_shows_success_message(): void
    {
        $category = CertificationCategory::factory()->create();

        $this->actingAs($this->admin)
            ->followingRedirects()
            ->patch(route('admin.certification-categories.update', $category), [
                'name' => '更新後の分類',
                'slug' => $category->slug,
                'sort_order' => 1,
            ])
            ->assertOk()
            ->assertSee('分類を更新しました。');
    }
}
