<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoogleCalendar;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fakes\FakeGoogleCalendarGateway;
use Tests\TestCase;

/**
 * GoogleCalendarService のトークン管理(期限切れの事前リフレッシュ / 401 での 1 回だけの再試行)とフォールバックを検証する。
 *
 * SDK の低レベル詳細はここに持ち込まず、カレンダー操作のまとまり単位(GoogleCalendarGateway)を偽物に差し替える。
 */
#[Group('external')]
class GoogleCalendarServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeGoogleCalendarGateway $gateway;

    private GoogleCalendarService $service;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeGoogleCalendarGateway;
        $this->app->instance(GoogleCalendarGateway::class, $this->gateway);
        $this->service = app(GoogleCalendarService::class);
        $this->coach = User::factory()->coach()->inProgress()->create();
    }

    private function connect(array $attributes = [], bool $expired = false): GoogleCredential
    {
        $factory = GoogleCredential::factory()->forCoach($this->coach);

        return ($expired ? $factory->expired() : $factory)->create([
            'access_token' => 'access-old',
            'refresh_token' => 'refresh-keep',
            ...$attributes,
        ]);
    }

    private function busy(): array
    {
        $day = CarbonImmutable::parse('2026-09-28 00:00');

        return $this->service->busyIntervals($this->coach->fresh(), $day, $day->endOfDay());
    }

    public function test_unconnected_coach_is_never_sent_to_google(): void
    {
        $this->assertSame([], $this->busy());
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_valid_token_is_used_without_refresh(): void
    {
        $this->connect();

        $this->busy();

        $this->assertSame(['busy'], array_column($this->gateway->calls, 'method'));
        $this->assertSame('access-old', $this->gateway->calls[0]['token']);
    }

    public function test_expired_token_is_refreshed_before_call_and_refresh_token_is_kept(): void
    {
        $credential = $this->connect(expired: true);

        $this->busy();

        $this->assertSame(['refresh', 'busy'], array_column($this->gateway->calls, 'method'));
        $this->assertSame('refreshed-access-token', $this->gateway->calls[1]['token']);
        $credential->refresh();
        $this->assertSame('refreshed-access-token', $credential->access_token);
        $this->assertSame('refresh-keep', $credential->refresh_token, 'リフレッシュ応答に refresh_token が無い場合は既存値を維持する');
        $this->assertTrue($credential->token_expires_at->isFuture());
    }

    public function test_token_expiring_within_a_minute_is_refreshed_in_advance(): void
    {
        // 境界: 失効まで 1 分以内は期限切れ扱い
        $this->connect(['token_expires_at' => now()->addSeconds(59)]);

        $this->busy();

        $this->assertSame(['refresh', 'busy'], array_column($this->gateway->calls, 'method'));
    }

    public function test_401_triggers_single_refresh_and_retry(): void
    {
        $this->connect();
        $this->gateway->expireOnce = true;

        $this->busy();

        $this->assertSame(['busy', 'refresh', 'busy'], array_column($this->gateway->calls, 'method'));
        $this->assertSame('refreshed-access-token', $this->gateway->calls[2]['token']);
    }

    public function test_refresh_failure_falls_back_to_no_busy_time_and_logs_warning(): void
    {
        Log::spy();
        $this->connect(expired: true);
        $this->gateway->failRefresh = true;

        $this->assertSame([], $this->busy());

        $this->assertSame(['refresh'], array_column($this->gateway->calls, 'method'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_api_failure_after_retry_falls_back_without_throwing(): void
    {
        $this->connect();
        $this->gateway->expireOnce = true;
        $this->gateway->failBusy = true;

        $this->assertSame([], $this->busy());
        $this->assertSame(['busy', 'refresh', 'busy'], array_column($this->gateway->calls, 'method'), '再試行は 1 回だけ');
    }

    public function test_same_period_is_requested_only_once_per_request(): void
    {
        $this->connect();

        $this->busy();
        $this->busy();

        $this->assertCount(1, $this->gateway->callsOf('busy'));
    }

    public function test_create_event_stores_event_id_and_failure_leaves_it_empty(): void
    {
        $this->connect();
        $meeting = Meeting::factory()->reserved()->forCoach($this->coach)->create();

        $this->service->createEventFor($meeting);
        $this->assertNotNull($meeting->fresh()->google_event_id);

        $failed = Meeting::factory()->reserved()->forCoach($this->coach)->create(['scheduled_at' => now()->addDays(3)]);
        $this->gateway->failCreate = true;

        $this->service->createEventFor($failed);
        $this->assertNull($failed->fresh()->google_event_id);
    }

    public function test_delete_event_clears_id_and_failure_keeps_it_for_later(): void
    {
        $this->connect();
        $meeting = Meeting::factory()->reserved()->forCoach($this->coach)->create(['google_event_id' => 'evt_1']);
        $kept = Meeting::factory()->reserved()->forCoach($this->coach)->create([
            'google_event_id' => 'evt_2',
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->service->deleteEventFor($meeting);
        $this->assertNull($meeting->fresh()->google_event_id);

        $this->gateway->failDelete = true;
        $this->service->deleteEventFor($kept);
        $this->assertSame('evt_2', $kept->fresh()->google_event_id);
    }

    public function test_meeting_without_event_id_does_not_call_delete(): void
    {
        $this->connect();
        $meeting = Meeting::factory()->reserved()->forCoach($this->coach)->create(['google_event_id' => null]);

        $this->service->deleteEventFor($meeting);

        $this->assertSame([], $this->gateway->callsOf('delete'));
    }
}
