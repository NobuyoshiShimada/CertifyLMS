<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Enrollment;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-08 回帰テスト: 受講生が目標受験日を更新すると、操作した受講登録の詳細画面に戻り、
 * 更新後の受験日と成功メッセージが同じ画面で確認できることを検証する。
 */
class UpdateExamDateRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_returns_to_same_enrollment_show_with_new_date_and_flash(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $enrollment = Enrollment::factory()
            ->for($student)
            ->for(Certification::factory()->published())
            ->learning()
            ->create(['exam_date' => now()->addDays(10)->toDateString()]);
        $newDate = now()->addDays(45)->toDateString();

        $response = $this->actingAs($student)
            ->from(route('dashboard.index'))
            ->followingRedirects()
            ->patch(route('enrollments.updateExamDate', $enrollment), ['exam_date' => $newDate]);

        $response->assertOk();
        $response->assertViewIs('enrollment.show');
        $this->assertSame($enrollment->id, $response->viewData('enrollment')->id);
        $response->assertSee($newDate);
        $response->assertSee('目標受験日を更新しました。');
    }
}
