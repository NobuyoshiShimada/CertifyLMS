<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\GoogleCalendar\GoogleAuthExpiredException;
use App\Services\GoogleCalendar\GoogleCalendarGateway;
use App\Services\GoogleCalendar\GoogleEventPayload;
use App\Services\GoogleCalendar\GoogleToken;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * テスト用の GoogleCalendarGateway。実 API には接続せず、呼び出しを記録し、失敗 / 401 を任意に起こせる。
 */
class FakeGoogleCalendarGateway implements GoogleCalendarGateway
{
    /** @var array<string, list<array{start: CarbonInterface, end: CarbonInterface}>> アクセストークンごとの予定 */
    public array $busyByToken = [];

    /** @var list<array{method: string, token?: string, payload?: GoogleEventPayload, event_id?: string}> */
    public array $calls = [];

    public bool $failBusy = false;

    public bool $failCreate = false;

    public bool $failDelete = false;

    public bool $failRefresh = false;

    public bool $failExchange = false;

    /** 次の API 呼び出しで 401(アクセストークン失効)を 1 度だけ返す */
    public bool $expireOnce = false;

    public ?GoogleToken $exchangeResult = null;

    public string $refreshedAccessToken = 'refreshed-access-token';

    public function authorizationUrl(string $state): string
    {
        $this->calls[] = ['method' => 'authorizationUrl'];

        return 'https://accounts.google.test/o/oauth2/auth?state='.$state;
    }

    public function exchangeAuthorizationCode(string $code): GoogleToken
    {
        $this->calls[] = ['method' => 'exchange'];

        if ($this->failExchange) {
            throw new RuntimeException('exchange failed');
        }

        return $this->exchangeResult ?? new GoogleToken('exchanged-access', 'exchanged-refresh', now()->addHour());
    }

    public function refreshAccessToken(string $refreshToken): GoogleToken
    {
        $this->calls[] = ['method' => 'refresh'];

        if ($this->failRefresh) {
            throw new RuntimeException('refresh failed');
        }

        return new GoogleToken($this->refreshedAccessToken, null, now()->addHour());
    }

    public function busyIntervals(string $accessToken, string $calendarId, CarbonInterface $from, CarbonInterface $to): array
    {
        $this->calls[] = ['method' => 'busy', 'token' => $accessToken];
        $this->maybeExpire();

        if ($this->failBusy) {
            throw new RuntimeException('freebusy failed');
        }

        return $this->busyByToken[$accessToken] ?? [];
    }

    public function createEvent(string $accessToken, string $calendarId, GoogleEventPayload $payload): string
    {
        $this->calls[] = ['method' => 'create', 'token' => $accessToken, 'payload' => $payload];
        $this->maybeExpire();

        if ($this->failCreate) {
            throw new RuntimeException('insert failed');
        }

        return 'evt_'.count($this->calls);
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $this->calls[] = ['method' => 'delete', 'token' => $accessToken, 'event_id' => $eventId];
        $this->maybeExpire();

        if ($this->failDelete) {
            throw new RuntimeException('delete failed');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function callsOf(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call['method'] === $method));
    }

    private function maybeExpire(): void
    {
        if ($this->expireOnce) {
            $this->expireOnce = false;

            throw new GoogleAuthExpiredException('expired');
        }
    }
}
