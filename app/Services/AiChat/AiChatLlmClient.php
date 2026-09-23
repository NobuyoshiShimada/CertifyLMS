<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * 生成 AI(LLM)への同期問い合わせ窓口。本チケットでは Gemini のみ実装する。
 *
 * 外部 API 呼び出しを本インターフェースの裏に隠し、テストでは実 API に依存しない実装へ差し替える。
 */
interface AiChatLlmClient
{
    /**
     * システム指示と会話履歴(古い順、最後が今回の受講生の質問)から応答を生成する。
     *
     * @param list<array{role: 'user'|'assistant', content: string}> $history
     *
     * @throws LlmRequestException 応答を得られなかった場合
     */
    public function generate(string $systemInstruction, array $history): LlmResponse;

    /**
     * API キーが設定され、問い合わせ可能な状態か。
     */
    public function isConfigured(): bool;
}
