<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * コーチ向け面談一覧の取得結果。
 */
final class IndexAsCoachResult
{
    public function __construct(
        public readonly LengthAwarePaginator $meetings,
        public readonly string $filter,
    ) {}
}
