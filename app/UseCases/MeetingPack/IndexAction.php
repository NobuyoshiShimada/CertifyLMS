<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\MeetingPack;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * admin 用の面談パック一覧をキーワード / 状態でフィルタして取得するユースケース。
 * 並び順は `MeetingPack::scopeOrdered()`(sort_order 昇順 → 作成日時降順)に従う。
 */
final class IndexAction
{
    public function __invoke(?string $keyword, ?MeetingPackStatus $status, int $perPage = 20): LengthAwarePaginator
    {
        return MeetingPack::query()
            ->withCount('payments')
            ->when($keyword, fn ($query, $keyword) => $query->where('name', 'like', "%{$keyword}%"))
            ->when($status, fn ($query, $status) => $query->where('status', $status->value))
            ->ordered()
            ->paginate($perPage)
            ->withQueryString();
    }
}
