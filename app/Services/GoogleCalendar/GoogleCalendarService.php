<?php

declare(strict_types=1);

namespace App\Services\GoogleCalendar;

use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 面談機能から Google カレンダー連携を使うための窓口(付加機能)。
 *
 * - アクセストークンが期限切れなら事前にリフレッシュし、API が失効(401)を返したら 1 度だけリフレッシュして再試行する
 * - リフレッシュも含めて失敗した場合は warning ログを残し、「連携なし」と同じ結果を返す(例外を呼出元へ伝播させない)
 *   → 空き枠は予定なし扱い / 予約・キャンセルは Event 操作なしで成立させ、面談機能の根幹を止めない
 * - 空き時間(freebusy)は 1 コーチ 1 リクエストで取得し、同一リクエスト中の同条件はメモ化する
 */
class GoogleCalendarService
{
    public const EVENT_DURATION_MINUTES = 60;

    /** @var array<string, list<array{start: CarbonInterface, end: CarbonInterface}>> */
    private array $busyCache = [];

    public function __construct(private readonly GoogleCalendarGateway $gateway) {}

    /**
     * コーチの期間内の予定がある時間帯を返す。未連携 / 取得失敗時は空配列(予定なし扱い)。
     *
     * @return list<array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function busyIntervals(User $coach, CarbonInterface $from, CarbonInterface $to): array
    {
        $credential = $coach->googleCredential;
        if ($credential === null) {
            return [];
        }

        $key = $credential->id.'|'.$from->getTimestamp().'|'.$to->getTimestamp();
        if (array_key_exists($key, $this->busyCache)) {
            return $this->busyCache[$key];
        }

        $busy = $this->withAccessToken(
            $credential,
            fn (string $token) => $this->gateway->busyIntervals($token, $credential->calendar_id, $from, $to),
            'freebusy',
        ) ?? [];

        return $this->busyCache[$key] = $busy;
    }

    /**
     * 期間 [start, start + 60 分) がコーチの Google カレンダーの予定と重なるか。未連携 / 取得失敗時は false。
     */
    public function isBusyAt(User $coach, CarbonInterface $start): bool
    {
        $dayStart = $start->copy()->startOfDay();
        $end = $start->copy()->addMinutes(self::EVENT_DURATION_MINUTES);

        foreach ($this->busyIntervals($coach, $dayStart, $dayStart->copy()->endOfDay()) as $interval) {
            if ($interval['start']->lt($end) && $interval['end']->gt($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 成立した面談の予定を担当コーチの Google カレンダーへ登録し、Event 識別子を面談に保存する。
     * 未連携 / 失敗時は何もしない(面談は成立したまま、Event 識別子は未設定)。
     */
    public function createEventFor(Meeting $meeting): void
    {
        $meeting->loadMissing(['coach.googleCredential', 'student', 'enrollment.certification']);
        $credential = $meeting->coach?->googleCredential;
        if ($credential === null) {
            return;
        }

        $payload = $this->payloadFor($meeting);
        $eventId = $this->withAccessToken(
            $credential,
            fn (string $token) => $this->gateway->createEvent($token, $credential->calendar_id, $payload),
            'events.insert',
            ['meeting_id' => $meeting->id],
        );

        if (is_string($eventId) && $eventId !== '') {
            $meeting->forceFill(['google_event_id' => $eventId])->save();
        }
    }

    /**
     * キャンセルされた面談の予定を担当コーチの Google カレンダーから削除する。
     * Event 未作成 / コーチが未連携(解除済) / 失敗時は何もしない(Event 識別子は保持し、再連携後の削除に備える)。
     */
    public function deleteEventFor(Meeting $meeting): void
    {
        if ($meeting->google_event_id === null) {
            return;
        }

        $meeting->loadMissing('coach.googleCredential');
        $credential = $meeting->coach?->googleCredential;
        if ($credential === null) {
            return;
        }

        $eventId = $meeting->google_event_id;
        $deleted = $this->withAccessToken(
            $credential,
            function (string $token) use ($credential, $eventId): bool {
                $this->gateway->deleteEvent($token, $credential->calendar_id, $eventId);

                return true;
            },
            'events.delete',
            ['meeting_id' => $meeting->id],
        );

        if ($deleted === true) {
            $meeting->forceFill(['google_event_id' => null])->save();
        }
    }

    public function payloadFor(Meeting $meeting): GoogleEventPayload
    {
        $studentName = $meeting->student?->name ?? '受講生';
        $certificationName = $meeting->enrollment?->certification?->name ?? '';
        $meetingUrl = $meeting->meeting_url_snapshot;

        $description = collect([
            '話題: '.($meeting->topic !== null && $meeting->topic !== '' ? $meeting->topic : '(未入力)'),
            $meetingUrl !== null && $meetingUrl !== '' ? "面談 URL: {$meetingUrl}" : '面談 URL: 未設定(プロフィールで固定面談 URL を登録してください)',
            'Certify LMS の面談予約から自動登録された予定です。',
        ])->implode("\n");

        return new GoogleEventPayload(
            summary: trim("【面談】{$studentName} さん {$certificationName}"),
            description: $description,
            location: $meetingUrl !== '' ? $meetingUrl : null,
            start: $meeting->scheduled_at->copy(),
            end: $meeting->scheduled_at->copy()->addMinutes(self::EVENT_DURATION_MINUTES),
        );
    }

    /**
     * 有効なアクセストークンで API を呼ぶ。期限切れは事前リフレッシュ、401 は 1 度だけリフレッシュして再試行。
     * いずれかが失敗したら warning ログを残して null を返す(連携なし相当のフォールバック)。
     *
     * @template T
     *
     * @param callable(string): T $request
     * @param array<string, mixed> $context
     *
     * @return T|null
     */
    private function withAccessToken(GoogleCredential $credential, callable $request, string $operation, array $context = []): mixed
    {
        $context = ['coach_id' => $credential->user_id, 'operation' => $operation, ...$context];

        try {
            if ($credential->isAccessTokenExpired()) {
                $this->refresh($credential);
            }

            try {
                return $request($credential->access_token);
            } catch (GoogleAuthExpiredException) {
                $this->refresh($credential);

                return $request($credential->access_token);
            }
        } catch (Throwable $e) {
            Log::warning('Google カレンダー連携の API 呼び出しに失敗したため連携なしとして継続します', [
                ...$context,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function refresh(GoogleCredential $credential): void
    {
        $token = $this->gateway->refreshAccessToken($credential->refresh_token);

        $credential->forceFill([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken ?? $credential->refresh_token,
            'token_expires_at' => $token->expiresAt,
        ])->save();
    }
}
