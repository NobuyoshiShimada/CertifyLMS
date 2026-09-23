<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Settings;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use App\Services\GoogleCalendar\GoogleToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeGoogleCalendarGateway;
use Tests\TestCase;

/**
 * Google カレンダー連携(開始 / コールバック / 解除)のアクセス制御・state 照合・連携状態表示を検証する機能テスト。
 */
class GoogleCalendarControllerTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleCalendarGateway $gateway;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGoogleCalendarGateway;
        $this->app->instance(GoogleCalendarGateway::class, $this->gateway);
        $this->coach = User::factory()->coach()->inProgress()->create();
    }

    private function stateFor(string $coachId, string $redirectPath = '/settings/availability'): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['coach_id' => $coachId, 'redirect_path' => $redirectPath])), '+/', '-_'), '=');
    }

    private function stateFromRedirect(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return json_decode((string) base64_decode(strtr((string) $query['state'], '-_', '+/')), true);
    }

    public function test_connect_redirects_to_google_with_state_of_logged_in_coach(): void
    {
        $response = $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.redirect', ['redirect_path' => '/settings/availability']));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.test/', $location);
        $this->assertSame(
            ['coach_id' => $this->coach->id, 'redirect_path' => '/settings/availability'],
            $this->stateFromRedirect($location),
        );
    }

    public function test_connect_ignores_external_redirect_path(): void
    {
        $location = (string) $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.redirect', ['redirect_path' => '//evil.example.com']))
            ->headers->get('Location');

        $this->assertSame('/settings/availability', $this->stateFromRedirect($location)['redirect_path']);
    }

    public function test_callback_stores_credential_for_primary_calendar_and_redirects(): void
    {
        $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.callback', ['code' => 'auth-code', 'state' => $this->stateFor($this->coach->id)]))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('success');

        $credential = $this->coach->fresh()->googleCredential;
        $this->assertNotNull($credential);
        $this->assertSame('exchanged-access', $credential->access_token);
        $this->assertSame('exchanged-refresh', $credential->refresh_token);
        $this->assertSame('primary', $credential->calendar_id);
        $this->assertNotNull($credential->connected_at);
    }

    public function test_reconnect_replaces_existing_credential_one_per_coach(): void
    {
        GoogleCredential::factory()->forCoach($this->coach)->create(['refresh_token' => 'old-refresh']);

        $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.callback', ['code' => 'auth-code', 'state' => $this->stateFor($this->coach->id)]));

        $this->assertSame(1, GoogleCredential::query()->where('user_id', $this->coach->id)->count());
        $this->assertSame('exchanged-refresh', $this->coach->fresh()->googleCredential->refresh_token);
    }

    public function test_callback_rejects_state_of_another_coach_with_400(): void
    {
        $other = User::factory()->coach()->inProgress()->create();

        $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.callback', ['code' => 'auth-code', 'state' => $this->stateFor($other->id)]))
            ->assertStatus(400);

        $this->assertDatabaseCount('google_credentials', 0);
        $this->assertSame([], $this->gateway->callsOf('exchange'));
    }

    public function test_callback_rejects_missing_or_broken_state_with_400(): void
    {
        $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.callback', ['code' => 'auth-code']))
            ->assertStatus(400);
        $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.callback', ['code' => 'auth-code', 'state' => 'not-base64-json']))
            ->assertStatus(400);

        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_callback_without_refresh_token_is_rejected_with_400(): void
    {
        $this->gateway->exchangeResult = new GoogleToken('access-only', null, now()->addHour());

        $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.callback', ['code' => 'auth-code', 'state' => $this->stateFor($this->coach->id)]))
            ->assertStatus(400);

        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_callback_when_user_denied_consent_redirects_with_error(): void
    {
        $this->actingAs($this->coach)
            ->get(route('settings.google-calendar.callback', ['error' => 'access_denied', 'state' => $this->stateFor($this->coach->id)]))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('error');

        $this->assertDatabaseCount('google_credentials', 0);
    }

    public function test_disconnect_deletes_credential_but_keeps_event_ids(): void
    {
        GoogleCredential::factory()->forCoach($this->coach)->create();
        $meeting = Meeting::factory()->reserved()->forCoach($this->coach)->create(['google_event_id' => 'evt_keep']);

        $this->actingAs($this->coach)
            ->delete(route('settings.google-calendar.destroy'))
            ->assertRedirect('/settings/availability')
            ->assertSessionHas('success');

        $this->assertNull($this->coach->fresh()->googleCredential);
        $this->assertSame('evt_keep', $meeting->fresh()->google_event_id);
    }

    /**
     * @dataProvider nonCoachProvider
     */
    public function test_non_coach_gets_403_on_all_routes(string $role): void
    {
        $user = User::factory()->{$role}()->inProgress()->create();

        $this->actingAs($user)->get(route('settings.google-calendar.redirect'))->assertForbidden();
        $this->actingAs($user)
            ->get(route('settings.google-calendar.callback', ['code' => 'c', 'state' => $this->stateFor($user->id)]))
            ->assertForbidden();
        $this->actingAs($user)->delete(route('settings.google-calendar.destroy'))->assertForbidden();

        $this->assertDatabaseCount('google_credentials', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonCoachProvider(): array
    {
        return ['受講生' => ['student'], '管理者' => ['admin']];
    }

    public function test_meeting_settings_tab_shows_connection_state(): void
    {
        $this->actingAs($this->coach)
            ->get(route('settings.availability.index'))
            ->assertOk()
            ->assertSee('未連携')
            ->assertSee(route('settings.google-calendar.redirect', ['redirect_path' => '/settings/availability']), false);

        GoogleCredential::factory()->forCoach($this->coach)->create();

        // 1 回目の表示で読み込んだリレーション(未連携)のキャッシュを持ち越さないよう読み直してログインする
        $this->actingAs($this->coach->fresh())
            ->get(route('settings.availability.index'))
            ->assertOk()
            ->assertSee('連携中')
            ->assertSee(route('settings.google-calendar.destroy'), false);
    }
}
