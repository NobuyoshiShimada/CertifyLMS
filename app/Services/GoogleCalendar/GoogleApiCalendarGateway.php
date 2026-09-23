<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\FreeBusyRequest;
use Google\Service\Calendar\FreeBusyRequestItem;
use Google\Service\Exception as GoogleServiceException;
use RuntimeException;

/**
 * google/apiclient による GoogleCalendarGateway 実装。
 *
 * スコープは予定の作成 / 削除(calendar.events)と空き時間の参照(calendar.freebusy)のみ。
 * 認可 URL は access_type=offline + prompt=consent とし、再連携時もリフレッシュトークンが返るようにする。
 */
final class GoogleApiCalendarGateway implements GoogleCalendarGateway
{
    private const SCOPES = [Calendar::CALENDAR_EVENTS, Calendar::CALENDAR_FREEBUSY];

    /** @var Closure(): Client */
    private readonly Closure $clientFactory;

    /**
     * @param (Closure(): Client)|null $clientFactory Google\Client の生成方法(テストで SDK クライアントを差し替えるため。既定は new Client)
     */
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        ?Closure $clientFactory = null,
    ) {
        $this->clientFactory = $clientFactory ?? fn (): Client => new Client;
    }

    public function authorizationUrl(string $state): string
    {
        $client = $this->oauthClient();
        $client->setState($state);

        return $client->createAuthUrl();
    }

    public function exchangeAuthorizationCode(string $code): GoogleToken
    {
        return $this->toToken($this->oauthClient()->fetchAccessTokenWithAuthCode($code));
    }

    public function refreshAccessToken(string $refreshToken): GoogleToken
    {
        return $this->toToken($this->oauthClient()->fetchAccessTokenWithRefreshToken($refreshToken));
    }

    public function busyIntervals(string $accessToken, string $calendarId, CarbonInterface $from, CarbonInterface $to): array
    {
        $request = new FreeBusyRequest;
        $request->setTimeMin($from->toRfc3339String());
        $request->setTimeMax($to->toRfc3339String());
        $request->setTimeZone(config('app.timezone'));
        $item = new FreeBusyRequestItem;
        $item->setId($calendarId);
        $request->setItems([$item]);

        $response = $this->call(fn () => $this->calendar($accessToken)->freebusy->query($request));

        $calendar = $response->getCalendars()[$calendarId] ?? null;
        if ($calendar === null) {
            return [];
        }
        if (! empty($calendar->getErrors())) {
            throw new RuntimeException('Google freebusy がカレンダーのエラーを返しました。');
        }

        $intervals = [];
        foreach ($calendar->getBusy() as $period) {
            $intervals[] = [
                'start' => CarbonImmutable::parse($period->getStart())->setTimezone(config('app.timezone')),
                'end' => CarbonImmutable::parse($period->getEnd())->setTimezone(config('app.timezone')),
            ];
        }

        return $intervals;
    }

    public function createEvent(string $accessToken, string $calendarId, GoogleEventPayload $payload): string
    {
        $event = new Event;
        $event->setSummary($payload->summary);
        $event->setDescription($payload->description);
        if ($payload->location !== null) {
            $event->setLocation($payload->location);
        }
        $event->setStart($this->eventDateTime($payload->start));
        $event->setEnd($this->eventDateTime($payload->end));

        $created = $this->call(fn () => $this->calendar($accessToken)->events->insert($calendarId, $event));

        return (string) $created->getId();
    }

    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        try {
            $this->call(fn () => $this->calendar($accessToken)->events->delete($calendarId, $eventId));
        } catch (GoogleServiceException $e) {
            // 既に削除済み(404 / 410)は目的を達しているため成功扱い
            if (! in_array($e->getCode(), [404, 410], true)) {
                throw $e;
            }
        }
    }

    private function oauthClient(): Client
    {
        $client = ($this->clientFactory)();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    private function calendar(string $accessToken): Calendar
    {
        $client = ($this->clientFactory)();
        $client->setAccessToken($accessToken);

        return new Calendar($client);
    }

    /**
     * @template T
     *
     * @param callable(): T $request
     *
     * @return T
     */
    private function call(callable $request): mixed
    {
        try {
            return $request();
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 401) {
                throw new GoogleAuthExpiredException('Google のアクセストークンが失効しています。', 401, $e);
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $token
     */
    private function toToken(array $token): GoogleToken
    {
        if (isset($token['error']) || ! isset($token['access_token'])) {
            throw new RuntimeException('Google のトークン取得に失敗しました: '.($token['error'] ?? 'unknown'));
        }

        return new GoogleToken(
            accessToken: (string) $token['access_token'],
            refreshToken: isset($token['refresh_token']) ? (string) $token['refresh_token'] : null,
            expiresAt: now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        );
    }

    private function eventDateTime(CarbonInterface $at): EventDateTime
    {
        $dateTime = new EventDateTime;
        $dateTime->setDateTime($at->toRfc3339String());
        $dateTime->setTimeZone(config('app.timezone'));

        return $dateTime;
    }
}
