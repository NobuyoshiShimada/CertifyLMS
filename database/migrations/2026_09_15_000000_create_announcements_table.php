<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 管理者による受講生向け一斉お知らせ配信の履歴テーブル。
 *
 * 配信は即時・不可逆(編集/再配信/取消なし)なため、update系のカラムは持たない。
 * target_certification_id / target_user_id は target_type に応じてどちらか一方のみ設定される。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 200);
            $table->text('body');
            $table->string('target_type', 20);
            $table->foreignUlid('target_certification_id')->nullable()->constrained('certifications')->nullOnDelete();
            $table->foreignUlid('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('dispatched_count')->default(0);
            $table->dateTime('dispatched_at')->nullable();
            $table->foreignUlid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
