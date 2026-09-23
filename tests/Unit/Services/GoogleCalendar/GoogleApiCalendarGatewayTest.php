<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GoogleCalendar;

use App\Services\GoogleCalendar\GoogleApiCalendarGateway;
use App\Services\GoogleCalendar\GoogleAuthExpiredException;
use App\Services\GoogleCalendar\GoogleEventPayload;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\FreeBusyCalendar;
use Google\Service\Calendar\FreeBusyResponse;
use Google\Service\Calendar\TimePeriod;
use Google\Service\Exception as GoogleServiceException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * google/apiclient による Gateway を、SDK のクライアント(Google\Client)を Mockery で差し替えて検証する。
 *
 * SDK は Laravel の Http Facade ではなく独自の HTTP クライアント(Guzzle)で通信するため Http::fake は効かない。
 * そこで通信の出口である Client::execute / トークン取得メソッドだけをモックし、それ以外(認可 URL 生成・リクエスト組立)は本物を使う。
 */
#[Group('external')]
class GoogleApiCalendarGatewayTest extends TestCase
{
    private const CALENDAR_ID = 'primary';

    /** @var Client&MockInterface */
    private Client $client;

    private GoogleApiCalendarGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Tokyo']);

        // コンストラクタは本物を通し、外部通信する execute / fetchAccessToken* だけを差し替える(未定義の呼び出しは本物に委譲)
        $this->client = Mockery::mock(Client::class, [[]])->makePartial();
        $this->gateway = new GoogleApiCalendarGateway(
            'client-id.apps.googleusercontent.com',
            'client-secret',
            'http://localhost/settings/google-calendar/callback',
            fn () => $this->client,
        );
    }

    // ---- 認可フロー ----

    public function test_authorization_url_requests_offline_access_with_consent_and_minimum_scopes(): void
    {
        $url = $this->gateway->authorizationUrl('state-token');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://accounts.google.com/', $url);
        $this->assertSame('client-id.apps.googleusercontent.com', $query['client_id']);
        $this->assertSame('http://localhost/settings/google-calendar/callback', $query['redirect_uri']);
        $this->assertSame('state-token', $query['state']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertEqualsCanonicalizing(
            ['https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.freebusy'],
            explode(' ', $query['scope']),
        );
        $this->client->shouldNotHaveReceived('execute');
    }

    public function test_exchange_authorization_code_returns_token_with_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00'));
        $this->client->shouldReceive('fetchAccessTokenWithAuthCode')->once()->with('auth-code')->andReturn([
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_in' => 3599,
        ]);

        $token = $this->gateway->exchangeAuthorizationCode('auth-code');

        $this->assertSame('access-1', $token->accessToken);
        $this->assertSame('refresh-1', $token->refreshToken);
        $this->assertSame('2026-09-24 10:59:59', $token->expiresAt->format('Y-m-d H:i:s'));
    }

    public function test_exchange_error_response_throws(): void
    {
        $this->client->shouldReceive('fetchAccessTokenWithAuthCode')->once()->andReturn([
            'error' => 'invalid_grant',
            'error_description' => 'Bad Request',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid_grant');

        $this->gateway->exchangeAuthorizationCode('used-code');
    }

    public function test_refresh_returns_new_access_token_without_rotating_refresh_token(): void
    {
        $this->client->shouldReceive('fetchAccessTokenWithRefreshToken')->once()->with('refresh-1')->andReturn([
            'access_token' => 'access-2',
            'expires_in' => 3600,
        ]);

        $token = $this->gateway->refreshAccessToken('refresh-1');

        $this->assertSame('access-2', $token->accessToken);
        $this->assertNull($token->refreshToken, 'リフレッシュ応答に refresh_token が無ければ null(既存値を維持するのは呼出側の責務)');
    }

    public function test_refresh_with_revoked_refresh_token_throws(): void
    {
        $this->client->shouldReceive('fetchAccessTokenWithRefreshToken')->once()->andReturn(['error' => 'invalid_grant']);

        $this->expectException(RuntimeException::class);

        $this->gateway->refreshAccessToken('revoked');
    }

    // ---- 空き時間(freebusy) ----

    public function test_busy_intervals_are_converted_to_app_timezone(): void
    {
        $calendar = new FreeBusyCalendar;
        $calendar->setBusy([$this->period('2026-09-28T01:00:00Z', '2026-09-28T02:30:00Z')]);
        $response = new FreeBusyResponse;
        $response->setCalendars([self::CALENDAR_ID => $calendar]);

        $this->client->shouldReceive('execute')->once()->andReturnUsing(function (RequestInterface $request) use ($response) {
            $body = json_decode((string) $request->getBody(), true);
            $this->assertSame('POST', $request->getMethod());
            $this->assertStringContainsString('calendar/v3/freeBusy', (string) $request->getUri());
            // 認可ヘッダは execute 内で付与されるため、クライアントに渡されたアクセストークンで確認する
            $this->assertSame('access-1', $this->client->getAccessToken()['access_token']);
            $this->assertSame([['id' => self::CALENDAR_ID]], $body['items']);
            $this->assertSame('Asia/Tokyo', $body['timeZone']);

            return $response;
        });

        $intervals = $this->gateway->busyIntervals(
            'access-1',
            self::CALENDAR_ID,
            CarbonImmutable::parse('2026-09-28 00:00', 'Asia/Tokyo'),
            CarbonImmutable::parse('2026-09-28 23:59', 'Asia/Tokyo'),
        );

        $this->assertCount(1, $intervals);
        $this->assertSame('2026-09-28 10:00', $intervals[0]['start']->format('Y-m-d H:i'));
        $this->assertSame('2026-09-28 11:30', $intervals[0]['end']->format('Y-m-d H:i'));
    }

    public function test_busy_intervals_are_empty_when_calendar_is_missing_from_response(): void
    {
        $this->client->shouldReceive('execute')->once()->andReturn(new FreeBusyResponse);

        $this->assertSame([], $this->gateway->busyIntervals('access-1', self::CALENDAR_ID, now(), now()->addDay()));
    }

    public function test_busy_intervals_throw_when_calendar_reports_errors(): void
    {
        $calendar = new FreeBusyCalendar;
        $calendar->setErrors([['domain' => 'global', 'reason' => 'notFound']]);
        $response = new FreeBusyResponse;
        $response->setCalendars([self::CALENDAR_ID => $calendar]);
        $this->client->shouldReceive('execute')->once()->andReturn($response);

        $this->expectException(RuntimeException::class);

        $this->gateway->busyIntervals('access-1', self::CALENDAR_ID, now(), now()->addDay());
    }

    public function test_401_is_translated_to_auth_expired_exception(): void
    {
        $this->client->shouldReceive('execute')->once()->andThrow(new GoogleServiceException('Invalid Credentials', 401));

        $this->expectException(GoogleAuthExpiredException::class);

        $this->gateway->busyIntervals('expired', self::CALENDAR_ID, now(), now()->addDay());
    }

    // ---- 予定の作成 / 削除 ----

    public function test_create_event_sends_payload_and_returns_event_id(): void
    {
        $created = new Event;
        $created->setId('evt_123');

        $this->client->shouldReceive('execute')->once()->andReturnUsing(function (RequestInterface $request) use ($created) {
            $body = json_decode((string) $request->getBody(), true);
            $this->assertStringContainsString('calendars/primary/events', (string) $request->getUri());
            $this->assertSame('【面談】受講生 さん', $body['summary']);
            $this->assertSame('https://meet.example.test/abc', $body['location']);
            $this->assertSame('2026-09-28T10:00:00+09:00', $body['start']['dateTime']);
            $this->assertSame('2026-09-28T11:00:00+09:00', $body['end']['dateTime']);

            return $created;
        });

        $eventId = $this->gateway->createEvent('access-1', self::CALENDAR_ID, new GoogleEventPayload(
            summary: '【面談】受講生 さん',
            description: '話題: 相談',
            location: 'https://meet.example.test/abc',
            start: CarbonImmutable::parse('2026-09-28 10:00', 'Asia/Tokyo'),
            end: CarbonImmutable::parse('2026-09-28 11:00', 'Asia/Tokyo'),
        ));

        $this->assertSame('evt_123', $eventId);
    }

    public function test_delete_event_succeeds(): void
    {
        $this->client->shouldReceive('execute')->once()->andReturnUsing(function (RequestInterface $request) {
            $this->assertSame('DELETE', $request->getMethod());
            $this->assertStringContainsString('calendars/primary/events/evt_123', (string) $request->getUri());

            return null;
        });

        $this->gateway->deleteEvent('access-1', self::CALENDAR_ID, 'evt_123');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function alreadyDeletedStatuses(): array
    {
        return ['404 Not Found' => [404], '410 Gone' => [410]];
    }

    #[DataProvider('alreadyDeletedStatuses')]
    public function test_deleting_already_deleted_event_is_treated_as_success(int $status): void
    {
        $this->client->shouldReceive('execute')->once()->andThrow(new GoogleServiceException('gone', $status));

        $this->gateway->deleteEvent('access-1', self::CALENDAR_ID, 'evt_deleted');

        $this->addToAssertionCount(1);
    }

    public function test_delete_server_error_is_rethrown(): void
    {
        $this->client->shouldReceive('execute')->once()->andThrow(new GoogleServiceException('backend error', 500));

        $this->expectException(GoogleServiceException::class);

        $this->gateway->deleteEvent('access-1', self::CALENDAR_ID, 'evt_123');
    }

    public function test_delete_with_expired_token_throws_auth_expired(): void
    {
        $this->client->shouldReceive('execute')->once()->andThrow(new GoogleServiceException('Invalid Credentials', 401));

        $this->expectException(GoogleAuthExpiredException::class);

        $this->gateway->deleteEvent('expired', self::CALENDAR_ID, 'evt_123');
    }

    private function period(string $start, string $end): TimePeriod
    {
        $period = new TimePeriod;
        $period->setStart($start);
        $period->setEnd($end);

        return $period;
    }
}
