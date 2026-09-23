<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 相談の会話とメッセージ。
 *
 * - 会話は受講生本人のもの。教材(Section)から始めた会話は section_id と、その資格の受講登録を持つ
 * - 削除は物理削除のみ(会話を削除するとメッセージも連動削除)。受講生本人の手動操作でのみ削除する
 * - AI 応答の運用観測メタデータ(モデル名 / トークン数 / 応答時間)をメッセージに記録する(受講生には非表示)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignUlid('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('title', 100);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
            $table->index(['user_id', 'section_id']);
        });

        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_chat_conversation_id')->constrained('ai_chat_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('status', 20);
            $table->text('content');
            $table->text('error_detail')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->timestamps();

            $table->index(['ai_chat_conversation_id', 'created_at']);
            $table->index(['role', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_conversations');
    }
};
