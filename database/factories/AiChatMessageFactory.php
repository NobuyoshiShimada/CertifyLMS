<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiChatMessage>
 */
class AiChatMessageFactory extends Factory
{
    protected $model = AiChatMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_chat_conversation_id' => AiChatConversation::factory(),
            'role' => AiChatMessageRole::User->value,
            'status' => AiChatMessageStatus::Completed->value,
            'content' => '過去問の効率的な進め方を教えてください。',
        ];
    }

    public function forConversation(AiChatConversation $conversation): static
    {
        return $this->state(fn () => ['ai_chat_conversation_id' => $conversation->id]);
    }

    public function assistant(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant->value,
            'content' => 'まずは直近 3 年分を時間を計って解き、間違えた分野を教材で復習しましょう。',
            'model' => 'gemini-2.5-flash-lite',
            'input_tokens' => 120,
            'output_tokens' => 80,
            'response_time_ms' => 1500,
        ]);
    }

    public function error(): static
    {
        return $this->state(fn () => [
            'role' => AiChatMessageRole::Assistant->value,
            'status' => AiChatMessageStatus::Error->value,
            'content' => '',
            'error_detail' => 'Gemini API HTTP 503',
        ]);
    }
}
