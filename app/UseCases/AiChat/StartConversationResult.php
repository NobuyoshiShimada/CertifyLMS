<?php

declare(strict_types=1);

namespace App\UseCases\AiChat;

use App\Models\AiChatConversation;

/**
 * 会話の作成 / 再開の結果(created=false は既存の教材会話の再開)。
 */
final class StartConversationResult
{
    public function __construct(
        public readonly AiChatConversation $conversation,
        public readonly bool $created,
    ) {}
}
