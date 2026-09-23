<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\UseCases\Meeting\UpsertMemoAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertMemoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_memo_for_reserved_meeting(): void
    {
        $meeting = Meeting::factory()->reserved()->create();

        $memo = app(UpsertMemoAction::class)($meeting, '初回メモ');

        $this->assertSame('初回メモ', $memo->body);
        $this->assertSame($meeting->id, $memo->meeting_id);
    }

    public function test_updates_existing_memo_of_completed_meeting_without_duplicating(): void
    {
        $meeting = Meeting::factory()->completed()->create();
        MeetingMemo::factory()->forMeeting($meeting)->create(['body' => '旧メモ']);

        app(UpsertMemoAction::class)($meeting, '更新後メモ');

        $this->assertSame(1, MeetingMemo::query()->where('meeting_id', $meeting->id)->count());
        $this->assertSame('更新後メモ', MeetingMemo::query()->where('meeting_id', $meeting->id)->value('body'));
    }

    public function test_canceled_meeting_rejects_memo(): void
    {
        $meeting = Meeting::factory()->canceled()->create();

        try {
            app(UpsertMemoAction::class)($meeting, 'メモ');
            $this->fail('MeetingStatusTransitionException が投げられるはず');
        } catch (MeetingStatusTransitionException) {
        }

        $this->assertDatabaseCount('meeting_memos', 0);
    }
}
