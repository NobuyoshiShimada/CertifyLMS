<?php

declare(strict_types=1);

namespace App\Services\AiChat;

/**
 * LLM の応答本文と運用観測メタデータ。
 */
final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
    ) {}
}
