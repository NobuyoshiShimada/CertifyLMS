<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use Carbon\CarbonInterface;

/**
 * Google OAuth トークンの取得結果。リフレッシュトークンは初回同意時(と再同意時)のみ返る。
 */
final class GoogleToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly CarbonInterface $expiresAt,
    ) {}
}
