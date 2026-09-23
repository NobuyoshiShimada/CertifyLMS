<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * 開発用 Google カレンダー連携データ。coach@ を連携済、coach2@ を未連携にする。
 *
 * coach@ のトークンはダミー値のため、実際の Google API 呼び出しは失敗し「連携なし」相当のフォールバックで動く
 * (面談設定タブの連携状態表示 / 連携解除の確認用)。実際の空き枠除外・予定登録は README の手順で実アカウントを連携して確認する。
 */
class GoogleCredentialSeeder extends Seeder
{
    public function run(): void
    {
        $coach = User::query()->where('email', 'coach@certify-lms.test')->first();

        if ($coach === null) {
            $this->command?->warn('GoogleCredentialSeeder: coach@certify-lms.test が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        GoogleCredential::query()->updateOrCreate(
            ['user_id' => $coach->id],
            [
                'access_token' => 'dummy-access-token-for-local-development',
                'refresh_token' => 'dummy-refresh-token-for-local-development',
                'token_expires_at' => now()->subMinute(),
                'calendar_id' => GoogleCredential::PRIMARY_CALENDAR,
                'connected_at' => now()->subDays(7),
            ],
        );
    }
}
