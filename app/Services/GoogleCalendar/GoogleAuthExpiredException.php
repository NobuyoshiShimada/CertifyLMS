<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use RuntimeException;

/**
 * Google API がアクセストークンの失効(401)を返したことを表す。GoogleCalendarService がリフレッシュして再試行する。
 */
final class GoogleAuthExpiredException extends RuntimeException {}
