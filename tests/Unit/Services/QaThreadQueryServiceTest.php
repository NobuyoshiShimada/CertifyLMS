<?php

namespace Tests\Unit\Services;

use App\Enums\QaThreadStatus;
use App\Models\QaThread;
use App\Services\QaThreadQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaThreadQueryService によるデータ検索・絞り込みロジックを検証する単体テスト。
 */
class QaThreadQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 検索キーワードを指定した際、タイトルまたは本文に対象文字を含むスレッドだけが絞り込まれることを検証する。
     *
     * @return void
     */
    public function test_get_paginated_threads_filters_by_keyword(): void
    {
        // ヒットすべきデータ
        QaThread::factory()->create(['title' => 'Gitの使い方について']);
        // ヒットしてはいけないデータ
        QaThread::factory()->create(['title' => 'Dockerの環境構築']);

        $service = new QaThreadQueryService();
        $result = $service->getPaginatedThreads(['keyword' => 'Git']);

        $this->assertEquals(1, $result->total());
        $this->assertEquals('Gitの使い方について', $result->first->title);
    }
}
