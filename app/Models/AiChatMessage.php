<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use Database\Factories\AiChatMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI 相談の 1 メッセージ(受講生の質問 / AI の応答)。
 *
 * AI 応答には運用観測メタデータ(model / input_tokens / output_tokens / response_time_ms)を記録する。受講生には表示しない。
 * AI 応答に失敗した場合は status=error で保存し、error_detail に失敗理由(上流の HTTP ステータス等)を残す。
 */
class AiChatMessage extends Model
{
    /** @use HasFactory<AiChatMessageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'ai_chat_conversation_id',
        'role',
        'status',
        'content',
        'error_detail',
        'model',
        'input_tokens',
        'output_tokens',
        'response_time_ms',
    ];

    protected $casts = [
        'role' => AiChatMessageRole::class,
        'status' => AiChatMessageStatus::class,
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'response_time_ms' => 'integer',
    ];

    /**
     * @return BelongsTo<AiChatConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiChatConversation::class, 'ai_chat_conversation_id');
    }
}
