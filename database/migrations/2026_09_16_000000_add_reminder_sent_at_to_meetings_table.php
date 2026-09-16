<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 面談リマインダー(前日 / 1時間前)の送信済み記録用カラムを追加する。
 *
 * status カラム自体は reserved のまま変化しないため、AutoCompleteMeetingAction のような
 * status 遷移による冪等性確保ができない。専用の送信済みタイムスタンプで多重配信を防ぐ。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dateTime('eve_reminder_sent_at')->nullable()->after('completed_at');
            $table->dateTime('one_hour_before_reminder_sent_at')->nullable()->after('eve_reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn(['eve_reminder_sent_at', 'one_hour_before_reminder_sent_at']);
        });
    }
};
