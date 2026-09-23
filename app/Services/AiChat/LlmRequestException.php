<?php

declare(strict_types=1);

namespace App\Services\AiChat;

use RuntimeException;
use Throwable;

/**
 * LLM から応答を得られなかった(通信失敗 / 上流のエラー応答 / 空応答)ことを表す。
 *
 * upstreamStatus は上流の HTTP ステータス(通信自体の失敗は null)。画面の案内文の出し分けに使う。
 */
final class LlmRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $upstreamStatus = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
