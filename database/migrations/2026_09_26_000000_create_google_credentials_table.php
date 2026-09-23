<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * コーチの Google カレンダー連携情報(1 コーチ : 1 連携、プライマリカレンダー固定)と、
 * 面談ごとに作成した Google カレンダー Event の識別子。
 *
 * トークンは本チケットでは平文保存(本番運用では暗号化を推奨、README に記載)。
 * 連携解除しても meetings.google_event_id は保持し、再連携後のキャンセルで削除リクエストを送れるようにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->timestamp('connected_at');
            $table->timestamps();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->string('google_event_id')->nullable()->after('meeting_url_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });

        Schema::dropIfExists('google_credentials');
    }
};
