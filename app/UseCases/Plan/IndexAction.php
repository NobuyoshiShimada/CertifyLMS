<?php

declare(strict_types=1);

namespace App\UseCases\Plan;

use App\Enums\PlanStatus;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 管理画面のプラン一覧を取得するユースケース。
 *
 * 並び順は「公開中 → 下書き → アーカイブ」の状態優先 → 並び順 昇順 → 作成日時 降順。
 * 受講者数は withCount で 1 クエリに集約し、行ごとの count クエリ(N+1)を避ける。
 */
final class IndexAction
{
    public function __invoke(?string $keyword, ?PlanStatus $status, int $perPage = 20): LengthAwarePaginator
    {
        return Plan::query()
            ->withCount('users')
            ->when($keyword, fn ($query, $keyword) => $query->where('name', 'like', "%{$keyword}%"))
            ->when($status, fn ($query, $status) => $query->where('status', $status->value))
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                PlanStatus::Published->value,
                PlanStatus::Draft->value,
            ])
            ->ordered()
            ->paginate($perPage)
            ->withQueryString();
    }
}
