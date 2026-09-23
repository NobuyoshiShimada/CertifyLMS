<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use Carbon\CarbonInterface;

/**
 * Google カレンダーへ登録する面談予定の内容。
 */
final class GoogleEventPayload
{
    public function __construct(
        public readonly string $summary,
        public readonly string $description,
        public readonly ?string $location,
        public readonly CarbonInterface $start,
        public readonly CarbonInterface $end,
    ) {}
}
