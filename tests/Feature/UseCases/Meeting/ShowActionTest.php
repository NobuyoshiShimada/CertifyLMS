<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\MeetingMemo;
use App\Models\User;
use App\UseCases\Meeting\ShowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_loads_relations_needed_for_detail_view(): void
    {
        $canceler = User::factory()->student()->create();
        $meeting = Meeting::factory()->canceled()->create(['canceled_by_user_id' => $canceler->id]);
        MeetingMemo::factory()->forMeeting($meeting)->create(['body' => 'メモ']);

        $loaded = app(ShowAction::class)(Meeting::query()->findOrFail($meeting->id));

        foreach (['enrollment', 'coach', 'student', 'canceledBy', 'meetingMemo'] as $relation) {
            $this->assertTrue($loaded->relationLoaded($relation), "{$relation} が読み込まれているはず");
        }
        $this->assertTrue($loaded->enrollment->relationLoaded('certification'));
        $this->assertSame($canceler->id, $loaded->canceledBy->id);
        $this->assertSame('メモ', $loaded->meetingMemo->body);
    }
}
