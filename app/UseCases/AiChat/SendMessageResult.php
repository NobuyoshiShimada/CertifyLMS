<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;

/**
 * メッセージ送信の結果。AI 応答が失敗した場合は assistantMessage が error 状態で、upstreamStatus に上流の HTTP ステータスが入る。
 */
final class SendMessageResult
{
    public function __construct(
        public readonly AiChatMessage $userMessage,
        public readonly AiChatMessage $assistantMessage,
        public readonly AiChatConversation $conversation,
        public readonly ?int $upstreamStatus,
    ) {}

    public function failed(): bool
    {
        return $this->assistantMessage->status === AiChatMessageStatus::Error;
    }
}
