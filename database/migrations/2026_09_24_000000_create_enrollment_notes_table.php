<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 受講登録(Enrollment)配下のコーチメモ。担当コーチ / 管理者のみ扱い、受講生には一切見せない業務記録。
 * SoftDelete 不採用、親の物理削除で連動削除。作成者は不変で、作成者 User の物理削除は阻む。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enrollment_id')
                ->constrained('enrollments')
                ->cascadeOnDelete();
            $table->foreignUlid('author_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['enrollment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_notes');
    }
};
