<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 面談の (coach_id, scheduled_at) に UNIQUE 制約を追加し、同一コーチ × 同一時刻の二重予約を DB レベルで禁止する。
 *
 * 面談作成時の候補コーチ抽出(予約済コーチの除外)はコミット済みの予約しか見えないため、ほぼ同時の並行予約は
 * 両方とも同じコーチを「空き」と判断して INSERT に進む。本制約により後から INSERT した側が一意制約違反となり、
 * MeetingController::store の既存の変換で「空きコーチなし」(409)として拒否される。
 * (create_meetings_table の設計コメントにある UNIQUE が実際のスキーマに存在しなかったための追加)
 *
 * 適用前に同一コーチ × 同一時刻の重複行が既に存在する環境では、本 Migration は失敗する(重複の解消が先に必要)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->unique(['coach_id', 'scheduled_at'], 'meetings_coach_id_scheduled_at_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_coach_id_scheduled_at_unique');
        });
    }
};
