<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\AiChat\AiChatLlmClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI の API キーが未設定の環境では、AI 相談ルートへのリクエストを 500 + 利用不可の案内で止める。
 * (機能 OFF スイッチはルート自体を登録しないため 404 になり、本ミドルウェアとは別の扱い)
 */
final class EnsureAiChatConfigured
{
    public function __construct(private readonly AiChatLlmClient $llm) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->llm->isConfigured()) {
            abort(500, 'AI 相談機能は現在ご利用いただけません。');
        }

        return $next($request);
    }
}
