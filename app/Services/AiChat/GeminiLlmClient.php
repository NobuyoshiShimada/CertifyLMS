<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gemini API(generateContent)による AiChatLlmClient 実装。同期応答のみ(ストリーミングは使わない)。
 *
 * API キー / モデル / タイムアウトは config('ai-chat.gemini.*')(.env)から受け取る。
 */
final class GeminiLlmClient implements AiChatLlmClient
{
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
}
