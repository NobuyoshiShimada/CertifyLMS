<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Meeting;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingQuotaTransaction;
use App\Models\User;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-10 回帰テスト: 予約済み面談をキャンセルすると消費分 1 回が受講生の残数へ返却され、
 * キャンセルできない面談では返却されないことを残数(MeetingQuotaService::remaining)で検証する。
 */
class CancelRefundQuotaTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->inProgress()->create(['max_meetings' => 5]);
        $this->coach = User::factory()->coach()->create();
    }

    /**
     * 予約時と同じく残数を 1 消費した予約済み面談を作る。
     */
    private function reservedMeeting(): Meeting
    {
        $meeting = Meeting::factory()->reserved()->forCoach($this->coach)->forStudent($this->student)->create([
            'scheduled_at' => now()->addDays(3)->startOfHour(),
        ]);
        app(ConsumeQuotaAction::class)($this->student, $meeting->id);

        return $meeting;
    }

    private function remaining(): int
    {
        return app(MeetingQuotaService::class)->remaining($this->student->fresh());
    }

    /**
     * @dataProvider cancelerProvider
     */
    public function test_cancel_refunds_one_to_student_remaining(string $canceler): void
    {
        $meeting = $this->reservedMeeting();
        $before = $this->remaining();

        $this->actingAs($this->{$canceler})
            ->post(route('meetings.cancel', $meeting))
            ->assertRedirect(route('meetings.show', $meeting))
            ->assertSessionHas('success', '面談をキャンセルしました。面談回数を返却しました。');

        $this->assertSame(MeetingStatus::Canceled, $meeting->fresh()->status);
        $this->assertSame($before + 1, $this->remaining());
        $this->assertSame(1, MeetingQuotaTransaction::query()
            ->where('user_id', $this->student->id)
            ->where('related_meeting_id', $meeting->id)
            ->where('type', MeetingQuotaTransactionType::Refunded)
            ->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function cancelerProvider(): array
    {
        return ['受講生がキャンセル' => ['student'], 'コーチがキャンセル' => ['coach']];
    }

    public function test_already_canceled_meeting_is_not_refunded_twice(): void
    {
        $meeting = $this->reservedMeeting();
        $this->actingAs($this->student)->post(route('meetings.cancel', $meeting));
        $afterFirst = $this->remaining();

        $this->actingAs($this->student)->post(route('meetings.cancel', $meeting))->assertForbidden();

        $this->assertSame($afterFirst, $this->remaining());
    }

    public function test_started_meeting_cannot_be_canceled_and_is_not_refunded(): void
    {
        $meeting = $this->reservedMeeting();
        $before = $this->remaining();
        $this->travelTo($meeting->scheduled_at->copy()->addMinute());

        $this->actingAs($this->student)->post(route('meetings.cancel', $meeting));

        $this->assertSame(MeetingStatus::Reserved, $meeting->fresh()->status);
        $this->assertSame($before, $this->remaining());
    }
}
