<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiChatConversation;
use App\Models\User;

/**
 * AI 相談の会話の認可。会話はオーナー本人のみ操作できる(管理者 / コーチのバイパスは設けない)。
 *
 * 「学習中の受講生のみ」はルートの role:student + active-learning で担保する。
 */
class AiChatConversationPolicy
{
    public function view(User $auth, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }

    public function update(User $auth, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }

    public function delete(User $auth, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }

    public function sendMessage(User $auth, AiChatConversation $conversation): bool
    {
        return $conversation->user_id === $auth->id;
    }
}
