<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\NotificationQueryService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Class NotificationQueryServiceTest
 *
 * NotificationQueryServiceによるデータ取得・フィルタリング・揺れ許容を単体検証するUnitテスト。
 */
class NotificationQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private NotificationQueryService $queryService;

    protected function setUp(): void
    {
        parent::setUp();

        // テスト実行時のみ、DB内の「User」という文字列を「App\Models\User」クラスに解釈させるマッピングを登録
        Relation::morphMap([
            'User' => User::class,
        ]);

        $this->student = User::factory()->student()->create();
        $this->queryService = new NotificationQueryService;
    }

    /**
     * クエリサービスが、不完全な名前空間（User等）の表記揺れを許容して通知を取得できるか検証する。
     *
     * @return void
     */
    public function test_get_paginated_notifications_tolerates_morph_type_variations(): void
    {
        // 揺れパターンA (フルパス)
        DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\QuestionRepliedNotification',
            'notifiable_type' => 'App\Models\User',
            'notifiable_id' => $this->student->id,
            'data' => ['title' => 'A'],
        ]);

        // 揺れパターンB (モルフマップに定義された省略名)
        DatabaseNotification::create([
            'id' => Str::uuid()->toString(),
            'type' => 'App\Notifications\QuestionRepliedNotification',
            'notifiable_type' => 'User',
            'notifiable_id' => $this->student->id,
            'data' => ['title' => 'B'],
        ]);

        $result = $this->queryService->getPaginatedNotificationsForUser($this->student);

        $this->assertSame(2, $result->total());
    }
}
