<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * B-B-12 回帰テスト: オンボーディング完了で利用状態が受講中になり、ログアウト後も
 * 設定したメールアドレス・パスワードで再ログインしてプラン機能にアクセスできることを検証する。
 * ログイン側のガード(招待中は拒否)は緩めていないことも併せて確認する。
 */
class OnboardingReloginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Invitation $invitation;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->published()->create(['duration_days' => 90, 'default_meeting_quota' => 4]);
        $this->user = User::factory()->student()->invited()->withPlan($plan)->create(['email' => 'new-student@example.test']);
        $this->invitation = Invitation::factory()
            ->forUser($this->user)
            ->pending()
            ->create(['invited_by_user_id' => User::factory()->admin()->create()->id]);
    }

    private function onboard(): void
    {
        $url = URL::temporarySignedRoute('onboarding.store', $this->invitation->expires_at, ['invitation' => $this->invitation->id]);

        $this->post($url, [
            'name' => '新しい受講生',
            'password' => 'onboard-pass',
            'password_confirmation' => 'onboard-pass',
        ])->assertRedirect(route('dashboard.index'));
    }

    public function test_onboarded_user_can_log_in_again_and_use_plan_features(): void
    {
        $this->onboard();
        $this->assertSame(UserStatus::InProgress, $this->user->fresh()->status);

        $this->post(route('logout'));
        $this->assertGuest();

        $this->post(route('login'), [
            'email' => 'new-student@example.test',
            'password' => 'onboard-pass',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($this->user->fresh());

        // プラン機能(資格カタログ)にアクセスできる
        $this->get(route('certifications.index'))->assertOk();
    }

    public function test_invited_user_still_cannot_log_in(): void
    {
        $this->user->forceFill(['password' => Hash::make('some-pass')])->save();

        $this->post(route('login'), [
            'email' => 'new-student@example.test',
            'password' => 'some-pass',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
