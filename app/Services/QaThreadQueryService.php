<?php

namespace App\Services;

use App\Models\QaThread;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 質問スレッド（QaThread）の検索・絞り込み・ページネーションなどの参照系クエリを専門に扱うサービス。
 */
class QaThreadQueryService
{
    /**
     * 指定された検索フィルタ条件に基づいて、ページネーション付きの質問スレッド一覧を取得する。
     *
     * @param array $filters 検索フィルタ条件の配列（status, certification_id, keyword）
     * @param int $perPage 1ページあたりの表示件数
     * @return LengthAwarePaginator ページネーション付きの質問スレッドコレクション
     */
    public function getPaginatedThreads(array $filters, int $perPage = 10): LengthAwarePaginator
    {
        return QaThread::with(['user', 'certification'])
            ->withCount('replies')
            ->when($filters['status'] ?? null, function ($query, $status): void {
                $query->where('status', $status);
            })
            ->when($filters['certification_id'] ?? null, function ($query, $certId): void {
                $query->where('certification_id', $certId);
            })
            ->when($filters['keyword'] ?? null, function ($query, $keyword): void {
                // タイトルまたは本文の部分一致検索に強化
                $query->where(function ($q) use ($keyword) {
                    $q->where('title', 'like', "%{$keyword}%")
                      ->orWhere('body', 'like', "%{$keyword}%");
                });
            })
            ->latest()
            ->paginate($perPage);
    }
}
