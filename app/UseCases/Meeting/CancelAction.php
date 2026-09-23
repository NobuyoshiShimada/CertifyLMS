<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Exceptions\Mentoring\MeetingAlreadyStartedException;
use App\Exceptions\Mentoring\MeetingStatusTransitionException;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingCanceledNotification;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\UseCases\MeetingQuota\RefundQuotaAction;
use Illuminate\Support\Facades\DB;

/**
 * 当事者(受講生 or コーチ)による面談キャンセルユースケース。認可は呼出側(Policy)で行う。
 *
 * reserved かつ開始前のみキャンセル可。状態遷移と同一トランザクションで、消費済の面談回数 1 回分を受講生へ返却する。
 * Google カレンダーの予定削除と相手方への通知は commit 後に行う(失敗してもキャンセル・返却は巻き戻らない)。
 */
final class CancelAction
{
    public function __construct(
        private readonly RefundQuotaAction $refundAction,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    /**
     * @throws MeetingStatusTransitionException reserved 以外
     * @throws MeetingAlreadyStartedException 開始時刻を過ぎている
     */
    public function __invoke(Meeting $meeting, User $actor): Meeting
    {
        DB::transaction(function () use ($meeting, $actor) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                throw MeetingStatusTransitionException::forCancel();
            }

            if ($locked->scheduled_at->lessThanOrEqualTo(now())) {
                throw new MeetingAlreadyStartedException;
            }

            $locked->update([
                'status' => MeetingStatus::Canceled->value,
                'canceled_by_user_id' => $actor->id,
                'canceled_at' => now(),
            ]);

            // 予約時に消費した 1 回分を受講生へ返却する(コーチがキャンセルした場合も返却先は受講生)。
            // 状態遷移と同一トランザクションで記録し、片方だけ成立する不整合を防ぐ。
            ($this->refundAction)($locked->student, $locked->id);

            DB::afterCommit(fn () => $this->afterCanceled($meeting, $actor));
        });

        return $meeting;
    }

    private function afterCanceled(Meeting $meeting, User $actor): void
    {
        $meeting->refresh()->loadMissing(['coach', 'student']);

        $this->googleCalendar->deleteEventFor($meeting);

        $recipient = $actor->id === $meeting->student_id ? $meeting->coach : $meeting->student;

        if ($recipient && in_array($recipient->status, [UserStatus::InProgress, UserStatus::Graduated], true)) {
            $recipient->notify(new MeetingCanceledNotification([
                'title' => '面談がキャンセルされました',
                'message' => "{$actor->name} さんが面談をキャンセルしました。",
                'url' => route('meetings.show', $meeting),
            ]));
        }
    }
}
