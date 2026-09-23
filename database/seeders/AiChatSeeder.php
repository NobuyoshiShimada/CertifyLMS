<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * 開発用 AI 相談データ。固定 student@ に次の 3 会話を投入する(ContentSeeder / EnrollmentSeeder の後に実行)。
 *
 * - 教材(Section)から始めた会話(受講中資格の Section に紐づく)
 * - 教材によらない会話(デフォルト資格の文脈)
 * - AI 応答がエラー状態の会話(エラー表示 / 同じ内容の再送の確認用)
 */
class AiChatSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->first();

        if ($student === null) {
            $this->command?->warn('AiChatSeeder: student@certify-lms.test が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        $enrollment = $student->enrollments()
            ->whereIn('status', [EnrollmentStatus::Learning->value, EnrollmentStatus::Passed->value])
            ->orderBy('created_at')
            ->first();

        $section = $enrollment === null ? null : Section::query()
            ->whereHas('chapter.part', fn ($q) => $q->where('certification_id', $enrollment->certification_id))
            ->orderBy('order')
            ->first();

        if ($section !== null) {
            $this->conversation($student, $enrollment?->id, $section->id, "「{$section->title}」の要点整理", now()->subHours(3), [
                ['user', "「{$section->title}」のポイントを 3 つに絞って教えてください。", AiChatMessageStatus::Completed],
                ['assistant', "「{$section->title}」で押さえたいのは次の 3 点です。\n\n1. 用語の定義を正確に覚える\n2. 図解で全体像をつかむ\n3. 過去問で出題パターンを確認する", AiChatMessageStatus::Completed],
            ]);
        }

        $this->conversation($student, $student->default_enrollment_id, null, '学習計画の立て方', now()->subDays(2), [
            ['user', '試験まで 2 か月です。1 日 1 時間でどう計画を立てればいいですか？', AiChatMessageStatus::Completed],
            ['assistant', "前半 5 週でインプット、後半 3 週で過去問演習に充てるのがおすすめです。\n\n- 平日: 教材 1 セクション + 確認問題\n- 週末: 1 週間分の復習と模試 1 回", AiChatMessageStatus::Completed],
        ]);

        $this->conversation($student, $student->default_enrollment_id, null, '新規相談', now()->subDays(5), [
            ['user', '午後問題の時間配分のコツを教えてください。', AiChatMessageStatus::Completed],
            ['assistant', '', AiChatMessageStatus::Error],
        ]);
    }

    /**
     * @param list<array{0: string, 1: string, 2: AiChatMessageStatus}> $messages
     */
    private function conversation(User $student, ?string $enrollmentId, ?string $sectionId, string $title, Carbon $at, array $messages): void
    {
        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollmentId,
            'section_id' => $sectionId,
            'title' => $title,
            'last_message_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        foreach ($messages as $i => [$role, $content, $status]) {
            $isAssistant = $role === AiChatMessageRole::Assistant->value;
            $conversation->messages()->create([
                'role' => $role,
                'status' => $status->value,
                'content' => $content,
                'error_detail' => $status === AiChatMessageStatus::Error ? 'Gemini API HTTP 503' : null,
                'model' => $isAssistant ? 'gemini-2.5-flash-lite' : null,
                'input_tokens' => $isAssistant && $status === AiChatMessageStatus::Completed ? 180 : null,
                'output_tokens' => $isAssistant && $status === AiChatMessageStatus::Completed ? 120 : null,
                'response_time_ms' => $isAssistant ? 1800 : null,
                'created_at' => $at->copy()->addSeconds($i * 5),
                'updated_at' => $at->copy()->addSeconds($i * 5),
            ]);
        }
    }
}
