<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Gemini API(generateContent)による AiChatLlmClient 実装。同期応答のみ(ストリーミングは使わない)。
 *
 * 接続エラー / 429 / 5xx の一時的な失敗は 1 度だけ再試行する。API キー / モデル / タイムアウトは config('ai-chat.gemini.*')(.env)から受け取る。
 */
final class GeminiLlmClient implements AiChatLlmClient
{
    /** 一時的な失敗を含めた最大試行回数 */
    private const MAX_ATTEMPTS = 2;

    private const RETRY_DELAY_MS = 500;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    public function generate(string $systemInstruction, array $history): LlmResponse
    {
        $contents = array_map(fn (array $turn) => [
            'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $turn['content']]],
        ], $history);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                // 一時的な失敗(接続エラー / 429 / 5xx)は待機を挟んで 1 度だけ再試行する。最終失敗は下の分岐で例外へ変換する
                ->retry(self::MAX_ATTEMPTS, self::RETRY_DELAY_MS, fn (Throwable $e) => $this->isTransient($e), throw: false)
                ->withHeaders(['x-goog-api-key' => (string) $this->apiKey])
                ->acceptJson()
                ->post(rtrim($this->baseUrl, '/')."/models/{$this->model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
                    'contents' => $contents,
                ]);
        } catch (ConnectionException $e) {
            throw new LlmRequestException('Gemini API に接続できませんでした: '.$e->getMessage(), null, $e);
        }

        if ($response->failed()) {
            throw new LlmRequestException("Gemini API HTTP {$response->status()}", $response->status());
        }

        $parts = $response->json('candidates.0.content.parts', []);
        $text = trim(implode('', array_map(fn ($part) => (string) ($part['text'] ?? ''), is_array($parts) ? $parts : [])));

        if ($text === '') {
            $reason = (string) $response->json('candidates.0.finishReason', 'EMPTY');
            throw new LlmRequestException("Gemini API から応答本文が得られませんでした ({$reason})", $response->status());
        }

        return new LlmResponse(
            content: $text,
            model: (string) ($response->json('modelVersion') ?? $this->model),
            inputTokens: $response->json('usageMetadata.promptTokenCount'),
            outputTokens: $response->json('usageMetadata.candidatesTokenCount'),
        );
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->status() === 429 || $e->response->serverError());
    }
}
