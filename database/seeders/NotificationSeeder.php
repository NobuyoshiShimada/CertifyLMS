<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. 既存のユーザーを探し、なければファクトリの正しいStateを利用して生成
        $student = User::where('role', UserRole::Student->value)->first()
            ?? User::factory()->student()->create(['name' => 'テスト受講生']);

        $coach = User::where('role', UserRole::Coach->value)->first()
            ?? User::factory()->coach()->create(['name' => 'テストコーチ']);

        // 古い通知データを一度綺麗にリセット
        DB::table('notifications')->truncate();

        $notifications = [];

        // 受講生宛て通知：種別A (Q&A返信 - 外部画面リダイレクト型)
        for ($i = 1; $i <= 12; $i++) {
            $notifications[] = [
                'id' => Str::uuid()->toString(),
                'type' => 'App\Notifications\QuestionRepliedNotification',
                'notifiable_type' => get_class($student),
                'notifiable_id' => $student->id,
                'data' => json_encode([
                    'notification_type' => 'qa_reply_received',
                    'title' => "【質問回答】レッスン{$i}について回答が届きました",
                    'message' => "コーチから「Laravelの設計思想について」の質問に新しいアドバイスがあります。",
                    'url' => "/questions/{$i}",
                ]),
                'read_at' => $i % 3 === 0 ? Carbon::now()->subHours($i) : null, // 既読・未読を混在
                'created_at' => Carbon::now()->subHours($i),
                'updated_at' => Carbon::now()->subHours($i),
            ];
        }

        // 受講生宛て通知：種別B (課題添削 - 外部画面リダイレクト型)
        for ($i = 1; $i <= 8; $i++) {
            $notifications[] = [
                'id' => Str::uuid()->toString(),
                'type' => 'App\Notifications\AssignmentReviewedNotification',
                'notifiable_type' => get_class($student),
                'notifiable_id' => $student->id,
                'data' => json_encode([
                    'notification_type' => 'completion_approved',
                    'title' => "【課題添削】第{$i}章のレビューが完了しました",
                    'message' => "提出いただいたソースコードの添削結果とコメントが届いています。",
                    'url' => "/assignments/{$i}/review",
                ]),
                'read_at' => $i % 2 === 0 ? Carbon::now()->subDays($i) : null,
                'created_at' => Carbon::now()->subDays($i),
                'updated_at' => Carbon::now()->subDays($i),
            ];
        }

        // 受講生宛て通知：種別C (運営からのお知らせ - 自己完結型詳細ページ遷移)
        for ($i = 1; $i <= 5; $i++) {
            $notifications[] = [
                'id' => Str::uuid()->toString(),
                'type' => 'App\Notifications\AdminAnnouncementNotification',
                'notifiable_type' => get_class($student),
                'notifiable_id' => $student->id,
                'data' => json_encode([
                    'notification_type' => 'admin_announcement',
                    'title' => "【重要】システムメンテナンスのお知らせ (Vol.{$i})",
                    'message' => "サービス向上のための定期メンテナンスの実施スケジュールについてのお知らせです。",
                    'body' => "受講生の皆様へ\n\nいつもカリキュラムをご利用いただきありがとうございます。\nこちらは運営事務局からの定期アナウンス（第{$i}回）です。\n\nより快適な学習環境を提供するため、サーバーのアップデート作業を行います。\n何卒ご理解とご協力のほどよろしくお願いいたします。",
                ]),
                'read_at' => null,
                'created_at' => Carbon::now()->subDays($i + 10),
                'updated_at' => Carbon::now()->subDays($i + 10),
            ];
        }

        // コーチ宛て通知：種別D（Q&A投稿、課題提出）
        for ($i = 1; $i <= 5; $i++) {
            $notifications[] = [
                'id' => Str::uuid()->toString(),
                'type' => 'App\Notifications\NewQuestionPostedNotification',
                'notifiable_type' => get_class($coach),
                'notifiable_id' => $coach->id,
                'data' => json_encode([
                    'notification_type' => 'qa_reply_received',
                    'title' => "【新着質問】受講生から新しい質問が入りました",
                    'message' => "受講生の佐藤さんが「環境構築時のマイグレーションエラー」について質問しています。",
                    'url' => "/questions/coach-view/{$i}",
                ]),
                'read_at' => null,
                'created_at' => Carbon::now()->subMinutes($i * 15),
                'updated_at' => Carbon::now()->subMinutes($i * 15),
            ];
        }

        // データベースへまとめて挿入
        DB::table('notifications')->insert($notifications);

    }
}
