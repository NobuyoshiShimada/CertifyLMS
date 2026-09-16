<?php

declare(strict_types=1);

namespace App\Console\Commands\Mentoring;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\UseCases\Meeting\SendMeetingReminderAction;
use Illuminate\Console\Command;

/**
 * 予約済み面談に対して、前日 / 開始1時間前のリマインダー通知(アプリ内 + メール)を配信する Schedule Command。
 *
 * `--window=eve` は「明日」の暦日に予約された面談、`--window=one_hour_before` は
 * 「今から1時間以内」に開始する面談を対象にする。実際の重複配信防止は
 * SendMeetingReminderAction 側の送信済みフラグ(行ロック + 再確認)が最終防衛線となる。
 *
 * 処理中に絞り込み条件(送信済みフラグ)自体を更新するため、オフセットベースの chunk() では
 * 後続チャンクを取りこぼす(ScheduleCommandChunkTest 参照)。主キーカーソルベースの chunkById() を使う。
 */
class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders {--window=}';

    protected $description = '予約済み面談に対して前日 / 開始1時間前のリマインダー通知を配信する';

    public function handle(SendMeetingReminderAction $action): int
    {
        $window = MeetingReminderWindow::tryFrom((string) $this->option('window'));

        if ($window === null) {
            $this->error('--window は eve または one_hour_before を指定してください。');

            return self::FAILURE;
        }

        [$from, $to] = $this->resolveRange($window);
        $count = 0;

        Meeting::query()
            ->where('status', MeetingStatus::Reserved->value)
            ->whereBetween('scheduled_at', [$from, $to])
            ->whereNull($window->sentAtColumn())
            ->chunkById(100, function ($meetings) use ($action, $window, &$count): void {
                foreach ($meetings as $meeting) {
                    if ($action($meeting, $window)) {
                        $count++;
                    }
                }
            });

        $this->info("面談リマインダー({$window->label()})を {$count} 件配信しました。");

        return self::SUCCESS;
    }

    /**
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    private function resolveRange(MeetingReminderWindow $window): array
    {
        return match ($window) {
            MeetingReminderWindow::Eve => [now()->addDay()->startOfDay(), now()->addDay()->endOfDay()],
            MeetingReminderWindow::OneHourBefore => [now(), now()->addHour()],
        };
    }
}
