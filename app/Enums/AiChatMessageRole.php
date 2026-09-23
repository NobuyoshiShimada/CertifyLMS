<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AI 相談メッセージの送り手。
 */
enum AiChatMessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
