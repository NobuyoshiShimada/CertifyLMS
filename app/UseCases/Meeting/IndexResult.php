<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 受講生向け面談一覧の取得結果。
 */
final class IndexResult
{
    public function __construct(
        public readonly LengthAwarePaginator $meetings,
        public readonly string $filter,
        public readonly int $meetingsRemaining,
    ) {}
}
