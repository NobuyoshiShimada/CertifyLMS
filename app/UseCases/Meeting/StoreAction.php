<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Exceptions\MeetingQuota\InsufficientMeetingQuotaException;
use App\Exceptions\Mentoring\MeetingNoAvailableCoachException;
use App\Exceptions\Mentoring\MeetingOutOfAvailabilityException;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\User;
use App\Notifications\MeetingReservedNotification;
use App\Services\CoachMeetingLoadService;
use App\Services\GoogleCalendar\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use App\Services\MeetingQuotaService;
use App\UseCases\MeetingQuota\ConsumeQuotaAction;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 受講生の面談予約ユースケース。
 *
 * 残面談回数を確認し、空き枠から過去実績最少のコーチを自動割当して reserved で確定、面談回数 1 回分を消費する。
 * 同時刻の race condition は (coach_id, scheduled_at) UNIQUE 違反として検知し「空きコーチなし」へ変換する。
 * コーチへの通知と Google カレンダーへの予定登録は同一トランザクションの commit 後に行う
 * (失敗しても予約・面談回数消費は巻き戻らず、ロールバック時は発火しない)。
 */
final class StoreAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly CoachMeetingLoadService $coachLoadService,
        private readonly MeetingQuotaService $quotaService,
        private readonly ConsumeQuotaAction $consumeAction,
        private readonly GoogleCalendarService $googleCalendar,
    ) {}

    /**
     * @throws InsufficientMeetingQuotaException 残面談回数 0
     * @throws MeetingOutOfAvailabilityException 担当コーチの有効枠外
     * @throws MeetingNoAvailableCoachException 候補コーチ 0 名 / 同時刻の先行予約
     */
    public function __invoke(Enrollment $enrollment, Carbon $scheduledAt, string $topic): Meeting
    {
        $student = $enrollment->user;

        return DB::transaction(function () use ($enrollment, $student, $scheduledAt, $topic) {
            if ($this->quotaService->remaining($student) < 1) {
                throw new InsufficientMeetingQuotaException;
            }

            $this->availabilityService->validateSlot($enrollment->certification, $scheduledAt);

            $candidates = $this->findAvailableCoaches($enrollment->certification, $scheduledAt);
            if ($candidates->isEmpty()) {
                throw new MeetingNoAvailableCoachException;
            }

            $coach = $this->coachLoadService->leastLoadedCoach($candidates);

            try {
                $meeting = Meeting::create([
                    'enrollment_id' => $enrollment->id,
                    'coach_id' => $coach->id,
                    'student_id' => $student->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => MeetingStatus::Reserved->value,
                    'topic' => $topic,
                    'meeting_url_snapshot' => $coach->meeting_url,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // 同時刻に他受講生が先行予約した race condition: UNIQUE(coach_id, scheduled_at) で弾かれた
                throw new MeetingNoAvailableCoachException($e);
            }

            $transaction = ($this->consumeAction)($student, $meeting->id);
            $meeting->update(['meeting_quota_transaction_id' => $transaction->id]);

            $meeting = $meeting->fresh();

            DB::afterCommit(fn () => $this->afterReserved($meeting, $student));

            return $meeting;
        });
    }

    private function afterReserved(Meeting $meeting, User $student): void
    {
        $meeting->loadMissing('coach');
        $coach = $meeting->coach;

        if ($coach && in_array($coach->status, [UserStatus::InProgress, UserStatus::Graduated], true)) {
            $coach->notify(new MeetingReservedNotification([
                'title' => '新しい面談予約が入りました',
                'message' => "{$student->name} さんから面談予約が入りました。",
                'url' => route('meetings.show', $meeting),
            ]));
        }

        $this->googleCalendar->createEventFor($meeting);
    }

    /**
     * 担当コーチ集合のうち、(1) 当該時刻に有効な availability 枠があり、
     * (2) 当該時刻に reserved / completed の Meeting を持たないコーチ集合を返す。
     *
     * @return Collection<int, User>
     */
    private function findAvailableCoaches(Certification $certification, Carbon $scheduledAt): Collection
    {
        $time = $scheduledAt->format('H:i:s');

        return $certification->coaches()
            ->whereHas('coachAvailabilities', function ($q) use ($scheduledAt, $time) {
                $q->where('day_of_week', $scheduledAt->dayOfWeek)
                    ->where('is_active', true)
                    ->where('start_time', '<=', $time)
                    ->where('end_time', '>', $time);
            })
            ->whereDoesntHave('meetingsAsCoach', function ($q) use ($scheduledAt) {
                $q->where('scheduled_at', $scheduledAt)
                    ->whereIn('status', [MeetingStatus::Reserved->value, MeetingStatus::Completed->value]);
            })
            ->with('googleCredential')
            ->get()
            // Google カレンダー連携済コーチは予定と重なる時刻を候補から外す(未連携 / 取得失敗は予定なし扱い)
            ->reject(fn (User $coach) => $this->googleCalendar->isBusyAt($coach, $scheduledAt))
            ->values();
    }
}
