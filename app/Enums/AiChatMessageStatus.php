<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AI 相談メッセージの状態。
 *
 * pending(AI 応答待ち) → completed(応答完了) / error(AI 応答失敗)。受講生のメッセージは常に completed。
 * error のメッセージは AI へ引き渡す会話履歴から除外する。
 */
enum AiChatMessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Error = 'error';
}
