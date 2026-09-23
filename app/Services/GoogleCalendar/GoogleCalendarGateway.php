<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use Carbon\CarbonInterface;

/**
 * Google OAuth / Calendar API の呼び出し窓口。
 *
 * 外部 API 呼び出しを本インターフェースの裏に隠し、テストでは実 API に依存しない実装へ差し替える。
 * 失敗時は例外を投げる(アクセストークン失効は GoogleAuthExpiredException)。フォールバック判断は GoogleCalendarService が行う。
 */
interface GoogleCalendarGateway
{
    /**
     * 認可 URL を返す(オフラインアクセス + 再同意で、再連携時もリフレッシュトークンが返るようにする)。
     */
    public function authorizationUrl(string $state): string;

    /**
     * 認可コードをトークンへ交換する。
     */
    public function exchangeAuthorizationCode(string $code): GoogleToken;

    /**
     * リフレッシュトークンで新しいアクセストークンを取得する(失敗時は例外)。
     */
    public function refreshAccessToken(string $refreshToken): GoogleToken;

    /**
     * 期間内の予定がある時間帯(freebusy)を返す。
     *
     * @return list<array{start: CarbonInterface, end: CarbonInterface}>
     *
     * @throws GoogleAuthExpiredException アクセストークン失効
     */
    public function busyIntervals(string $accessToken, string $calendarId, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * 予定を作成し Event 識別子を返す。
     *
     * @throws GoogleAuthExpiredException アクセストークン失効
     */
    public function createEvent(string $accessToken, string $calendarId, GoogleEventPayload $payload): string;

    /**
     * 予定を削除する(既に存在しない予定は成功扱い)。
     *
     * @throws GoogleAuthExpiredException アクセストークン失効
     */
    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void;
}
