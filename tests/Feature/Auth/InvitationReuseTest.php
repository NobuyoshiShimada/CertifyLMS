<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InvitationStatus;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\InvitationTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * B-B-06 回帰テスト: 一度オンボーディングを完了した招待 URL は使用済みになり、
 * 同じ URL を開いても無効案内になり、再送信は 410 で拒否されて登録内容を上書きできないことを検証する。
 */
class InvitationReuseTest extends TestCase
{
    use RefreshDatabase;

    private Invitation $invitation;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->published()->create(['duration_days' => 90, 'default_meeting_quota' => 6]);
        $user = User::factory()->student()->invited()->withPlan($plan)->create();
        $this->invitation = Invitation::factory()
            ->forUser($user)
            ->pending()
            ->create(['invited_by_user_id' => User::factory()->admin()->create()->id]);
    }

    private function postUrl(): string
    {
        return URL::temporarySignedRoute('onboarding.store', $this->invitation->expires_at, ['invitation' => $this->invitation->id]);
    }

    private function onboard(string $name, string $password): TestResponse
    {
        return $this->post($this->postUrl(), [
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    public function test_first_onboarding_succeeds_and_marks_invitation_accepted(): void
    {
        $showUrl = app(InvitationTokenService::class)->generateUrl($this->invitation);
        $this->get($showUrl)->assertOk()->assertViewIs('auth.onboarding');

        $this->onboard('最初の名前', 'first-password')->assertRedirect(route('dashboard.index'));

        $this->assertAuthenticatedAs($this->invitation->user);
        $this->invitation->refresh();
        $this->assertSame(InvitationStatus::Accepted, $this->invitation->status);
        $this->assertNotNull($this->invitation->accepted_at);
    }

    public function test_reused_invitation_url_shows_invalid_view_and_rejects_resubmission_with_410(): void
    {
        $showUrl = app(InvitationTokenService::class)->generateUrl($this->invitation);
        $this->onboard('最初の名前', 'first-password');
        auth()->logout();

        // 同じ招待 URL を開き直すと、オンボーディング画面ではなく無効案内になる
        $this->get($showUrl)->assertViewIs('auth.invitation-invalid');

        // 同じ招待で再送信しても 410 で拒否され、名前・パスワードは上書きされない
        $this->onboard('上書きされた名前', 'second-password')->assertStatus(410);

        $user = $this->invitation->user->fresh();
        $this->assertSame('最初の名前', $user->name);
        $this->assertTrue(Hash::check('first-password', $user->password));
        $this->assertFalse(Hash::check('second-password', $user->password));
    }
}
