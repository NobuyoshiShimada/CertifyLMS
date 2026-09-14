<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\Certification;
use App\Models\User;
use App\Notifications\AdminAnnouncementNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Class AnnouncementSeeder
 *
 * 管理者お知らせ配信機能(S-B-08)の表示・配信履歴・受講生側の受信を検証するための初期データを生成する。
 * 配信対象の3種類(全受講生 / 資格指定 / ユーザー指定)それぞれの Announcement と、
 * 実際にその対象受講生へ届いた通知(notifications テーブル)を対で投入する。
 */
class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        // UserSeeder が固定管理者アカウントを必ず作成するため、ここでは新規作成しない。
        $admin = User::where('role', UserRole::Admin->value)->first();

        if ($admin === null) {
            $this->command?->warn('AnnouncementSeeder: 管理者 User が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        // 再実行時の重複防止(admin_announcement 通知は本シーダーのみが生成する)
        Announcement::query()->truncate();
        DB::table('notifications')->where('type', AdminAnnouncementNotification::class)->delete();

        // -------------------------------------------------------------
        // 種別1: 全受講生向け配信
        // -------------------------------------------------------------
        $allStudents = User::where('role', UserRole::Student->value)->active()->get();

        $this->dispatch(
            admin: $admin,
            targetType: AnnouncementTargetType::AllStudents,
            title: 'システムメンテナンスのお知らせ',
            body: "受講生の皆様へ\n\nいつもカリキュラムをご利用いただきありがとうございます。\nサーバーのアップデート作業を下記日程で実施いたします。\n作業中は一時的にサービスをご利用いただけません。\n何卒ご理解とご協力のほどよろしくお願いいたします。",
            recipients: $allStudents,
            targetCertificationId: null,
            targetUserId: null,
            dispatchedAt: Carbon::now()->subDays(5),
        );

        // -------------------------------------------------------------
        // 種別2: 資格指定配信(受講中の受講生が存在する資格のみ対象にできる)
        // -------------------------------------------------------------
        $certification = Certification::query()
            ->where('status', CertificationStatus::Published->value)
            ->whereHas('enrollments', fn ($query) => $query->learning())
            ->first();

        if ($certification !== null) {
            $certificationStudents = User::where('role', UserRole::Student->value)
                ->active()
                ->whereHas('enrollments', function ($query) use ($certification) {
                    $query->where('certification_id', $certification->id)->learning();
                })
                ->get();

            $this->dispatch(
                admin: $admin,
                targetType: AnnouncementTargetType::Certification,
                title: "「{$certification->name}」教材アップデートのお知らせ",
                body: "「{$certification->name}」を受講中の皆様へ\n\n教材の一部を更新いたしました。最新の内容をご確認のうえ、引き続き学習を進めてください。",
                recipients: $certificationStudents,
                targetCertificationId: $certification->id,
                targetUserId: null,
                dispatchedAt: Carbon::now()->subDays(3),
            );
        }

        // -------------------------------------------------------------
        // 種別3: ユーザー指定配信(個別フォロー連絡)
        // -------------------------------------------------------------
        $targetStudent = $allStudents->first();

        if ($targetStudent !== null) {
            $this->dispatch(
                admin: $admin,
                targetType: AnnouncementTargetType::User,
                title: '学習進捗についてのご連絡',
                body: "{$targetStudent->name} 様\n\n最近の学習状況を確認させていただきました。ご不明点やお困りごとがあれば、いつでもコーチまたは事務局までご連絡ください。",
                recipients: collect([$targetStudent]),
                targetCertificationId: null,
                targetUserId: $targetStudent->id,
                dispatchedAt: Carbon::now()->subDay(),
            );
        }
    }

    /**
     * Announcement レコードを1件作成し、対象受講生分の notifications 行を一括投入する。
     *
     * @param Collection<int, User> $recipients
     */
    private function dispatch(
        User $admin,
        AnnouncementTargetType $targetType,
        string $title,
        string $body,
        Collection $recipients,
        ?string $targetCertificationId,
        ?string $targetUserId,
        Carbon $dispatchedAt,
    ): void {
        $announcement = Announcement::create([
            'title' => $title,
            'body' => $body,
            'target_type' => $targetType,
            'target_certification_id' => $targetCertificationId,
            'target_user_id' => $targetUserId,
            'dispatched_count' => $recipients->count(),
            'dispatched_at' => $dispatchedAt,
            'created_by' => $admin->id,
        ]);
        $announcement->forceFill(['created_at' => $dispatchedAt, 'updated_at' => $dispatchedAt])->save();

        $rows = $recipients->values()->map(function (User $recipient, int $index) use ($title, $body, $dispatchedAt) {
            return [
                'id' => Str::uuid()->toString(),
                'type' => AdminAnnouncementNotification::class,
                'notifiable_type' => get_class($recipient),
                'notifiable_id' => $recipient->id,
                'data' => json_encode([
                    'notification_type' => 'admin_announcement',
                    'title' => $title,
                    'message' => $body,
                    'body' => $body,
                ]),
                // 既読・未読を混在させ、一覧表示の両パターンを確認できるようにする
                'read_at' => $index % 2 === 0 ? $dispatchedAt : null,
                'created_at' => $dispatchedAt,
                'updated_at' => $dispatchedAt,
            ];
        })->all();

        if (! empty($rows)) {
            DB::table('notifications')->insert($rows);
        }
    }
}
