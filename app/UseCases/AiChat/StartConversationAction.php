<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Exceptions\AiChat\SectionNotAccessibleException;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;
use App\Services\AiChat\AiChatContextBuilder;
use Illuminate\Support\Str;

/**
 * AI 相談の会話を作成 / 再開するユースケース。
 *
 * - ウィジェット + 教材(Section): その Section の既存会話があれば再開、なければ作成(同じ教材の会話を乱立させない)
 * - フル画面 / 教材以外のウィジェット: 常に作成(教材以外のウィジェットの「直前の会話の引き継ぎ」は JS の sessionStorage が担う)
 * - 教材の資格に学習中 / 修了の受講登録がなければ 403
 * - 暫定タイトルは「新規相談」。フル画面で初回メッセージ付きで作成した場合はその先頭 30 文字
 */
final class StartConversationAction
{
    public const SOURCE_WIDGET = 'widget';

    public const SOURCE_FULL_SCREEN = 'full-screen';

    public function __construct(private readonly AiChatContextBuilder $context) {}

    /**
     * @throws SectionNotAccessibleException
     */
    public function __invoke(User $student, string $source, ?string $sectionId, ?string $firstMessage): StartConversationResult
    {
        $section = $sectionId !== null ? Section::query()->with('chapter.part')->findOrFail($sectionId) : null;
        $enrollment = $this->context->resolveEnrollment($student, $section?->chapter?->part?->certification_id);

        if ($section !== null && $enrollment === null) {
            throw new SectionNotAccessibleException;
        }

        if ($section !== null && $source === self::SOURCE_WIDGET) {
            $existing = $student->aiChatConversations()
                ->where('section_id', $section->id)
                ->orderByDesc('last_message_at')
                ->first();

            if ($existing !== null) {
                return new StartConversationResult($existing, created: false);
            }
        }

        $title = $source === self::SOURCE_FULL_SCREEN && $firstMessage !== null && trim($firstMessage) !== ''
            ? Str::substr(trim(preg_replace('/\s+/u', ' ', $firstMessage) ?? ''), 0, 30)
            : (string) config('ai-chat.default_title');

        $conversation = AiChatConversation::query()->create([
            'user_id' => $student->id,
            'enrollment_id' => $enrollment?->id,
            'section_id' => $section?->id,
            'title' => $title,
            'last_message_at' => now(),
        ]);

        return new StartConversationResult($conversation, created: true);
    }
}
