<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\InvitationStatus;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * B-B-13 回帰テスト: オンボーディングでパスワードと確認用パスワードが一致しない(または確認用が空の)場合は
 * 422 の入力エラーで止まり、ユーザー・招待が変化しないこと、一致すれば従来どおり完了することを検証する。
 */
class OnboardingPasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const MISMATCH_MESSAGE = 'パスワード と確認用の入力が一致しません。';

    private Invitation $invitation;

    private ?string $originalPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $plan = Plan::factory()->published()->create(['duration_days' => 90, 'default_meeting_quota' => 4]);
        $user = User::factory()->student()->invited()->withPlan($plan)->create();
        $this->invitation = Invitation::factory()
            ->forUser($user)
            ->pending()
            ->create(['invited_by_user_id' => User::factory()->admin()->create()->id]);
        $this->originalPassword = $user->password;
    }

    private function postUrl(): string
    {
        return URL::temporarySignedRoute('onboarding.store', $this->invitation->expires_at, ['invitation' => $this->invitation->id]);
    }

    private function assertNotOnboarded(): void
    {
        $this->assertGuest();
        $user = $this->invitation->user->fresh();
        $this->assertSame(UserStatus::Invited, $user->status);
        $this->assertSame($this->originalPassword, $user->password);
        $this->assertSame(InvitationStatus::Pending, $this->invitation->fresh()->status);
    }

    /**
     * @dataProvider invalidConfirmationProvider
     */
    public function test_mismatched_or_missing_confirmation_returns_422_and_does_not_onboard(?string $confirmation): void
    {
        $this->postJson($this->postUrl(), array_filter([
            'name' => '受講太郎',
            'password' => 'secret-pass',
            'password_confirmation' => $confirmation,
        ], fn ($value) => $value !== null))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password' => self::MISMATCH_MESSAGE]);

        $this->assertNotOnboarded();
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidConfirmationProvider(): array
    {
        return ['確認用が異なる' => ['different-pass'], '確認用が未入力' => [null]];
    }

    public function test_form_submission_with_mismatch_shows_japanese_error_on_form(): void
    {
        $this->post($this->postUrl(), [
            'name' => '受講太郎',
            'password' => 'secret-pass',
            'password_confirmation' => 'different-pass',
        ])->assertSessionHasErrors(['password' => self::MISMATCH_MESSAGE]);

        $this->assertNotOnboarded();
    }

    public function test_matching_confirmation_completes_onboarding(): void
    {
        $this->post($this->postUrl(), [
            'name' => '受講太郎',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ])->assertRedirect(route('dashboard.index'));

        $user = $this->invitation->user->fresh();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertSame(InvitationStatus::Accepted, $this->invitation->fresh()->status);
    }
}
