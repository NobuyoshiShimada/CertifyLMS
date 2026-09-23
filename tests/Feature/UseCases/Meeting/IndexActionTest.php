<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Meeting;

use App\Models\Meeting;
use App\Models\User;
use App\UseCases\Meeting\IndexAction;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexActionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Meeting $upcoming;

    private Meeting $completed;

    private Meeting $canceled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 3]);
        $this->upcoming = Meeting::factory()->reserved()->inFuture()->forStudent($this->student)->create();
        $this->completed = Meeting::factory()->completed()->forStudent($this->student)->create(['scheduled_at' => now()->subDays(2)]);
        $this->canceled = Meeting::factory()->canceled()->forStudent($this->student)->create(['scheduled_at' => now()->subDay()]);
        // 他の受講生の面談は含めない
        Meeting::factory()->reserved()->inFuture()->create();
    }

    public function test_default_filter_is_upcoming_and_returns_remaining_quota(): void
    {
        app(ConsumeQuotaAction::class)($this->student, $this->upcoming->id);

        $result = app(IndexAction::class)($this->student, null);

        $this->assertSame('upcoming', $result->filter);
        $this->assertSame([$this->upcoming->id], $result->meetings->pluck('id')->all());
        $this->assertSame(2, $result->meetingsRemaining);
        $this->assertTrue($result->meetings->first()->relationLoaded('coach'));
    }

    public function test_past_filter_returns_completed_and_canceled_newest_first(): void
    {
        $result = app(IndexAction::class)($this->student, 'past');

        $this->assertSame([$this->canceled->id, $this->completed->id], $result->meetings->pluck('id')->all());
    }

    public function test_all_filter_returns_every_own_meeting_newest_first(): void
    {
        $result = app(IndexAction::class)($this->student, 'all');

        $this->assertSame(
            [$this->upcoming->id, $this->canceled->id, $this->completed->id],
            $result->meetings->pluck('id')->all(),
        );
    }
}
