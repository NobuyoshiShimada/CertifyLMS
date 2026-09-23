<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 追加面談パックの購入記録(Stripe Checkout 1 セッション = 1 行)。
 *
 * - 決済額(amount) / 購入回数(quantity) は購入時点のスナップショット(面談パックの後日変更に影響されない)
 * - stripe_checkout_session_id を UNIQUE にし、同じ決済セッションで購入記録が重複作成されないことを構造で保証する
 * - 会計監査要件のため SoftDelete を採用
 *
 * あわせて meeting_quota_transactions.related_payment_id に FK を追加する(payments 導入時に追加する予定だったもの)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('meeting_pack_id')->constrained('meeting_packs')->restrictOnDelete();
            $table->string('stripe_checkout_session_id')->unique();
            $table->string('stripe_payment_intent_id')->nullable();
            $table->unsignedInteger('amount');
            $table->unsignedSmallInteger('quantity');
            $table->string('status', 20);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'created_at']);
            $table->index(['meeting_pack_id', 'status']);
        });

        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->foreign('related_payment_id')->references('id')->on('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
        });

        Schema::dropIfExists('payments');
    }
};
