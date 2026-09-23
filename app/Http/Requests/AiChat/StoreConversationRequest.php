<?php

declare(strict_types=1);

namespace App\Http\Requests\AiChat;

use App\UseCases\AiChat\StartConversationAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * AI 相談の会話作成(ウィジェット / フル画面の「新しい相談」)の入力検証。
 */
class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['nullable', 'string', 'in:'.StartConversationAction::SOURCE_WIDGET.','.StartConversationAction::SOURCE_FULL_SCREEN],
            'section_id' => ['nullable', 'string', 'exists:sections,id'],
            'message' => ['nullable', 'string', 'min:1', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'source' => '起動経路',
            'section_id' => '教材セクション',
            'message' => '最初のメッセージ',
        ];
    }

    public function source(): string
    {
        return (string) ($this->validated('source') ?? StartConversationAction::SOURCE_FULL_SCREEN);
    }
}
