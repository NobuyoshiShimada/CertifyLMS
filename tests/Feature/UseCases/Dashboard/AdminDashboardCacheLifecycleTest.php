<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Enums\EnrollmentStatus;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentStatusChangeService;
use App\UseCases\Dashboard\FetchAdminDashboardAction;
use App\UseCases\Dashboard\ViewModels\AdminDashboardViewModel;
use App\UseCases\Enrollment\FailAction;
use App\UseCases\Enrollment\StoreAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T-A-06 回帰テスト: TTL が設定値で効くこと、状態遷移の各経路で 2 キーとも無効化されること、
 * ロールバックされた遷移の後も古い値が残らないことを検証する。
 */
class AdminDashboardCacheLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Certification $certification;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->inProgress()->create();
        $this->certification = Certification::factory()->published()->create();
        Cache::flush();
    }

    private function fetch(): AdminDashboardViewModel
    {
        return app(FetchAdminDashboardAction::class)($this->admin);
    }

    private function assertBothKeysCached(bool $expected): void
    {
        $this->assertSame($expected, Cache::has(config('dashboard.admin_kpi_cache_key')));
        $this->assertSame($expected, Cache::has(config('dashboard.admin_completion_rate_cache_key')));
    }

    public function test_cache_expires_after_configured_ttl(): void
    {
        config(['dashboard.admin_stats_cache_ttl' => 60]);
        Enrollment::factory()->for($this->certification)->learning()->create();
        $this->fetch();
        Enrollment::factory()->for($this->certification)->learning()->create();

        $this->travel(59)->seconds();
        $this->assertSame(1, $this->fetch()->kpi['learning_count'], 'TTL 内はキャッシュの値');

        $this->travel(2)->seconds();
        $this->assertSame(2, $this->fetch()->kpi['learning_count'], 'TTL 失効後は再集計される');
    }

    public function test_new_enrollment_invalidates_both_caches(): void
    {
        $this->fetch();
        $this->assertBothKeysCached(true);
        $student = User::factory()->student()->inProgress()->create();

        app(StoreAction::class)($student, ['certification_id' => $this->certification->id]);

        $this->assertBothKeysCached(false);
        $after = $this->fetch();
        $this->assertSame(1, $after->kpi['learning_count']);
        $this->assertSame(0.0, $after->completionRateByCertification->firstWhere('certification_id', $this->certification->id)['completion_rate']);
    }

    public function test_fail_transition_invalidates_both_caches(): void
    {
        $enrollment = Enrollment::factory()->for($this->certification)->learning()->create();
        $this->fetch();

        app(FailAction::class)($enrollment, $this->admin, '期限切れ');

        $this->assertBothKeysCached(false);
        $this->assertSame(1, $this->fetch()->kpi['failed_count']);
    }

    public function test_rolled_back_transition_does_not_leave_stale_cache(): void
    {
        $enrollment = Enrollment::factory()->for($this->certification)->learning()->create();
        $this->fetch();

        try {
            DB::transaction(function () use ($enrollment) {
                $enrollment->update(['status' => EnrollmentStatus::Passed]);
                app(EnrollmentStatusChangeService::class)->recordStatusChange($enrollment, EnrollmentStatus::Learning, EnrollmentStatus::Passed, $this->admin);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        // 無効化された後の再集計はロールバック後の状態(受講中 1 / 合格 0)を返す
        $after = $this->fetch();
        $this->assertSame(1, $after->kpi['learning_count']);
        $this->assertSame(0, $after->kpi['passed_count']);
    }
}
