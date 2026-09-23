<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * B-B-16 回帰テスト: プラン機能は受講中ユーザーのみ利用でき、修了 / 退会 / 招待中は 403 になること、
 * 修了ユーザーでもログインとガード対象外の画面(ダッシュボード / 受講登録 / 通知 / 設定)は利用できることを検証する。
 */
class PlanFeatureAccessByStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * プラン機能(active-learning ガード対象)の代表ルート。
     *
     * @return array<string, string>
     */
    private function planFeatureRoutes(): array
    {
        return [
            '資格カタログ' => route('certifications.index'),
            '教材' => route('learning.index'),
            'チャット' => route('chat.index'),
            '面談' => route('meetings.index'),
            '質問掲示板' => route('qa-board.index'),
            '面談回数履歴' => route('meeting-quota.history'),
        ];
    }

    /**
     * @dataProvider nonActiveStatusProvider
     */
    public function test_non_in_progress_student_is_forbidden_from_plan_features(UserStatus $status): void
    {
        $user = User::factory()->student()->create(['status' => $status->value]);

        foreach ($this->planFeatureRoutes() as $label => $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    /**
     * @return array<string, array{UserStatus}>
     */
    public static function nonActiveStatusProvider(): array
    {
        return [
            '修了' => [UserStatus::Graduated],
            '退会' => [UserStatus::Withdrawn],
            '招待中' => [UserStatus::Invited],
        ];
    }

    public function test_in_progress_student_can_still_access_plan_features(): void
    {
        $user = User::factory()->student()->inProgress()->create();

        foreach ($this->planFeatureRoutes() as $label => $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            $this->assertNotSame(403, $status, "{$label} が受講中ユーザーに 403 を返している");
        }
    }

    public function test_graduated_student_can_still_use_non_plan_pages(): void
    {
        $user = User::factory()->student()->graduated()->create();

        foreach ([
            'ダッシュボード' => route('dashboard.index'),
            '受講登録一覧' => route('enrollments.index'),
            '通知' => route('notifications.index'),
            'プロフィール設定' => route('settings.profile.edit'),
        ] as $label => $url) {
            $status = $this->actingAs($user)->get($url)->getStatusCode();
            $this->assertNotSame(403, $status, "{$label} が修了ユーザーに 403 を返している");
            $this->assertLessThan(400, $status, "{$label} が {$status} を返している");
        }
    }

    public function test_graduated_student_can_still_log_in(): void
    {
        User::factory()->student()->graduated()->create([
            'email' => 'graduated@example.test',
            'password' => Hash::make('graduated-pass'),
        ]);

        $this->post(route('login'), [
            'email' => 'graduated@example.test',
            'password' => 'graduated-pass',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }
}
