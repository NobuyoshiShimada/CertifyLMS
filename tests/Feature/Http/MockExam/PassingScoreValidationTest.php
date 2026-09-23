<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-B-05 回帰テスト: 模試マスタの作成・編集で合格点は 1〜100(%) のみ受け付け、
 * 範囲外は日本語のバリデーションエラーで保存されないことを HTTP 経由で検証する。
 */
class PassingScoreValidationTest extends TestCase
{
    use RefreshDatabase;

    private const ERROR_MESSAGE = '合格点(%) は 1 から 100 の間で指定してください。';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $passingScore, ?Certification $certification = null): array
    {
        return array_filter([
            'certification_id' => $certification?->id,
            'title' => '第 1 回 本番形式',
            'description' => null,
            'order' => 0,
            'passing_score' => $passingScore,
        ], fn ($value, $key) => $key !== 'certification_id' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @dataProvider outOfRangeProvider
     */
    public function test_store_rejects_out_of_range_passing_score(int $score): void
    {
        $certification = Certification::factory()->published()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.mock-exams.store'), $this->payload($score, $certification))
            ->assertSessionHasErrors(['passing_score' => self::ERROR_MESSAGE]);

        $this->assertDatabaseCount('mock_exams', 0);
    }

    /**
     * @dataProvider outOfRangeProvider
     */
    public function test_update_rejects_out_of_range_passing_score(int $score): void
    {
        $mockExam = MockExam::factory()->create(['passing_score' => 60]);

        $this->actingAs($this->admin)
            ->put(route('admin.mock-exams.update', $mockExam), $this->payload($score))
            ->assertSessionHasErrors(['passing_score' => self::ERROR_MESSAGE]);

        $this->assertSame(60, $mockExam->fresh()->passing_score);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function outOfRangeProvider(): array
    {
        return ['上限超 101' => [101], '上限超 150' => [150], '下限未満 0' => [0]];
    }

    /**
     * @dataProvider boundaryProvider
     */
    public function test_store_and_update_accept_boundary_passing_score(int $score): void
    {
        $certification = Certification::factory()->published()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.mock-exams.store'), $this->payload($score, $certification))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('mock_exams', ['certification_id' => $certification->id, 'passing_score' => $score]);

        $mockExam = MockExam::factory()->create(['passing_score' => 60]);
        $this->actingAs($this->admin)
            ->put(route('admin.mock-exams.update', $mockExam), $this->payload($score))
            ->assertSessionHasNoErrors();
        $this->assertSame($score, $mockExam->fresh()->passing_score);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function boundaryProvider(): array
    {
        return ['下限 1' => [1], '上限 100' => [100]];
    }
}
