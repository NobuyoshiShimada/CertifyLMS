<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 管理者ダッシュボード集計のキャッシュ
    |--------------------------------------------------------------------------
    |
    | 全体 KPI と資格別修了率は全受講登録を走査する重い集計のため、一定時間キャッシュする。
    | 受講状態の遷移(EnrollmentStatusChangeService::recordStatusChange)で両キーを即時無効化し、
    | それ以外の変化(資格の公開・非公開など)は TTL の失効で反映する。
    |
    */

    'admin_stats_cache_ttl' => (int) env('ADMIN_DASHBOARD_CACHE_TTL', 300),

    'admin_kpi_cache_key' => 'dashboard:admin:kpi',

    'admin_completion_rate_cache_key' => 'dashboard:admin:completion-rate',

];
