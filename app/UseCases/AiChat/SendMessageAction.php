<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChat\DailyMessageLimitExceededException;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\User;
use App\Services\AiChat\AiChatContextBuilder;
use App\Services\AiChat\AiChatLlmClient;
use App\Services\AiChat\LlmRequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * 受講生のメッセージを送信し、AI 応答を同期取得して保存するユースケース。
 *
 * 1. 日次送信上限(失敗分も含めて受講生メッセージ数で数える)を超えていれば 429
 * 2. 受講生のメッセージを保存(AI が失敗しても消えない)
 * 3. 文脈付きシステム指示 + 直近の会話履歴(エラー状態を除く、既定 20 件)で AI に問い合わせる
 * 4. 成功: 応答を completed で保存(モデル名 / トークン数 / 応答時間を記録)。最初の応答ならタイトルを AI で生成
 *    失敗: 応答を error で保存し、上流の HTTP ステータスを結果に含める(呼出元は 502 を返す)
 */
final class SendMessageAction
{
    public function __construct(
        private readonly AiChatLlmClient $llm,
        private readonly AiChatContextBuilder $context,
    ) {}

    /**
     * @throws DailyMessageLimitExceededException
     */
    public function __invoke(User $student, AiChatConversation $conversation, string $content): SendMessageResult
    {
        if ($this->sentToday($student) >= (int) config('ai-chat.daily_message_limit')) {
            throw new DailyMessageLimitExceededException;
        }

        $userMessage = $conversation->messages()->create([
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => $content,
        ]);

        $isFirstAssistantReply = ! $conversation->messages()
            ->where('role', AiChatMessageRole::Assistant->value)
            ->where('status', AiChatMessageStatus::Completed->value)
            ->exists();

        $startedAt = hrtime(true);
        try {
            $response = $this->llm->generate($this->context->systemInstruction($conversation), $this->history($conversation));
            $assistantMessage = $conversation->messages()->create([
                'role' => AiChatMessageRole::Assistant->value,
                'status' => AiChatMessageStatus::Completed->value,
                'content' => $response->content,
                'model' => $response->model,
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'response_time_ms' => $this->elapsedMs($startedAt),
            ]);
            $upstreamStatus = null;
        } catch (LlmRequestException $e) {
            Log::channel('ai-chat')->warning('AI 応答の取得に失敗しました', [
                'conversation_id' => $conversation->id,
                'upstream_status' => $e->upstreamStatus,
                'message' => $e->getMessage(),
            ]);
            $assistantMessage = $conversation->messages()->create([
                'role' => AiChatMessageRole::Assistant->value,
                'status' => AiChatMessageStatus::Error->value,
                'content' => '',
                'error_detail' => $e->getMessage(),
                'model' => (string) config('ai-chat.gemini.model'),
                'response_time_ms' => $this->elapsedMs($startedAt),
            ]);
            $upstreamStatus = $e->upstreamStatus;
        }

        $conversation->forceFill(['last_message_at' => now()])->save();

        if ($assistantMessage->status === AiChatMessageStatus::Completed && $isFirstAssistantReply) {
            $this->generateTitle($conversation, $content, $assistantMessage->content);
        }

        return new SendMessageResult($userMessage, $assistantMessage, $conversation->refresh(), $upstreamStatus);
    }

    public function sentToday(User $student): int
    {
        return AiChatMessage::query()
            ->where('role', AiChatMessageRole::User->value)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereHas('conversation', fn ($q) => $q->where('user_id', $student->id))
            ->count();
    }

    /**
     * AI へ渡す直近の会話履歴(古い順、エラー状態は除外)。
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function history(AiChatConversation $conversation): array
    {
        return $conversation->messages()
            ->reorder()
            ->where('status', AiChatMessageStatus::Completed->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) config('ai-chat.history_limit'))
            ->get()
            ->reverse()
            ->map(fn (AiChatMessage $message) => [
                'role' => $message->role === AiChatMessageRole::Assistant ? 'assistant' : 'user',
                'content' => $message->content,
            ])
            ->values()
            ->all();
    }

    /**
     * 最初のやり取りの内容から会話タイトルを生成する。無効化時 / 失敗時は暫定タイトルのまま(本流の応答は阻害しない)。
     */
    private function generateTitle(AiChatConversation $conversation, string $question, string $answer): void
    {
        if (! (bool) config('ai-chat.title_generation_enabled')) {
            return;
        }

        try {
            $response = $this->llm->generate(
                '以下の学習相談のやり取りに、内容が分かる日本語のタイトルを 20 文字以内で 1 つだけ付けてください。タイトルのみを出力し、記号や引用符は付けないでください。',
                [['role' => 'user', 'content' => "質問: {$question}\n回答: {$answer}"]],
            );
            $title = Str::substr(trim(str_replace(["\n", '"', '「', '」'], ' ', $response->content)), 0, 100);

            if ($title !== '') {
                $conversation->forceFill(['title' => $title])->save();
            }
        } catch (Throwable $e) {
            Log::channel('ai-chat')->info('会話タイトルの自動生成に失敗したため暫定タイトルを維持します', [
                'conversation_id' => $conversation->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
