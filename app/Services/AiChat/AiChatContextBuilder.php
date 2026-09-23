<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Enrollment;
use App\Models\User;

/**
 * AI への入力に自動で添える受講生の文脈(資格・教材)と、システム指示を組み立てる。
 *
 * | 会話の状態                          | 添える文脈                                      |
 * | 教材(Section)から始めた会話         | Part / Chapter / Section の見出し + 所属資格名 |
 * | 教材以外 + デフォルト資格あり        | デフォルト資格名のみ                             |
 * | 教材以外 + デフォルト資格なし        | なし(一般的な学習相談)                          |
 *
 * 教材はセクション本文を渡さず見出しのみ(外部 API の無料枠を圧迫しないため)。
 * 資格名は受講登録が学習中 / 修了(合格)のものだけを対象とする。
 */
class AiChatContextBuilder
{
    /**
     * 会話作成時に紐づける受講登録を決める(教材会話は Section の資格、それ以外はデフォルト資格)。
     */
    public function resolveEnrollment(User $student, ?string $certificationId): ?Enrollment
    {
        if ($certificationId !== null) {
            return $student->enrollments()
                ->where('certification_id', $certificationId)
                ->whereIn('status', self::activeStatuses())
                ->first();
        }

        $default = $student->defaultEnrollment;

        return $default !== null && in_array($default->status, [EnrollmentStatus::Learning, EnrollmentStatus::Passed], true)
            ? $default
            : null;
    }

    /**
     * 会話に添える文脈の行(空なら文脈なし)。
     *
     * @return list<string>
     */
    public function contextLines(AiChatConversation $conversation): array
    {
        $conversation->loadMissing(['section.chapter.part.certification', 'enrollment.certification', 'user.defaultEnrollment.certification']);

        $section = $conversation->section;
        if ($section !== null) {
            $chapter = $section->chapter;
            $part = $chapter?->part;

            return array_values(array_filter([
                ($part?->certification?->name) !== null ? "受講中の資格: {$part->certification->name}" : null,
                '閲覧中の教材:',
                $part !== null ? "- Part {$part->order}. {$part->title}" : null,
                $chapter !== null ? "- Chapter {$chapter->order}. {$chapter->title}" : null,
                "- Section {$section->order}. {$section->title}",
            ]));
        }

        $enrollment = $conversation->enrollment;
        if ($enrollment === null || ! in_array($enrollment->status, [EnrollmentStatus::Learning, EnrollmentStatus::Passed], true)) {
            $enrollment = $this->resolveEnrollment($conversation->user, null);
        }

        $certificationName = $enrollment?->certification?->name;

        return $certificationName !== null ? ["受講中の資格: {$certificationName}"] : [];
    }

    public function systemInstruction(AiChatConversation $conversation): string
    {
        $prompt = (string) config('ai-chat.system_prompt');
        $lines = $this->contextLines($conversation);

        if ($lines === []) {
            return $prompt."\n\n受講生の学習文脈は指定されていません。一般的な学習相談として回答してください。";
        }

        return $prompt."\n\n以下は受講生の現在の学習文脈です。回答の前提にしてください。\n".implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function activeStatuses(): array
    {
        return [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value];
    }
}
