<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Enums\MeetingReminderWindow;
use App\Enums\MeetingStatus;
use App\Enums\UserStatus;
use App\Models\Meeting;
use App\Notifications\MeetingReminderNotification;
use Illuminate\Support\Facades\DB;

/**
 * Schedule Command(`notifications:send-meeting-reminders`)から呼ばれる、面談リマインダー配信ユースケース。
 *
 * AutoCompleteMeetingAction と同じ考え方で、`lockForUpdate()` で対象行を取得し直し
 * トランザクション内で「まだ reserved か」「まだ当該 window の送信済みフラグが立っていないか」を
 * 再確認してから UPDATE することで、多重起動・並行実行でも二重配信されないことを保証する(冪等)。
 *
 * @see \App\Console\Commands\Mentoring\SendMeetingRemindersCommand
 */
final class SendMeetingReminderAction
{
    public function __invoke(Meeting $meeting, MeetingReminderWindow $window): bool
    {
        $sent = DB::transaction(function () use ($meeting, $window) {
            $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== MeetingStatus::Reserved) {
                return false;
            }

            $column = $window->sentAtColumn();

            if ($locked->{$column} !== null) {
                return false;
            }

            $locked->update([$column => now()]);

            return true;
        });

        if (! $sent) {
            return false;
        }

        $meeting->loadMissing(['coach', 'student']);

        $payload = [
            'title' => '面談リマインダー',
            'message' => $this->buildMessage($meeting, $window),
            'url' => route('meetings.show', $meeting),
        ];

        foreach ([$meeting->coach, $meeting->student] as $recipient) {
            if ($recipient !== null && in_array($recipient->status, [UserStatus::InProgress, UserStatus::Graduated], true)) {
                $recipient->notify(new MeetingReminderNotification($payload));
            }
        }

        return true;
    }

    private function buildMessage(Meeting $meeting, MeetingReminderWindow $window): string
    {
        $when = $meeting->scheduled_at->format('Y/m/d H:i');

        return match ($window) {
            MeetingReminderWindow::Eve => "明日 {$when} に面談の予定があります。",
            MeetingReminderWindow::OneHourBefore => "まもなく{$when}に面談が始まります。",
        };
    }
}
