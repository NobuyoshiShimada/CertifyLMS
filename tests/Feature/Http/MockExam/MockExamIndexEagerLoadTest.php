<?php

declare(strict_types=1);

namespace Tests\Feature\Http\MockExam;

use App\Models\Certification;
use App\Models\MockExam;
use App\Models\MockExamQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T-A-01 回帰テスト: 模試マスタ一覧が関連情報を一括取得したうえで、各行の表示内容と並びを保つことを検証する。
 */
class MockExamIndexEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    public function test_index_renders_without_any_lazy_loading(): void
    {
        $admin = User::factory()->admin()->create();
        $certification = Certification::factory()->published()->create();
        MockExam::factory()->count(3)->forCertification($certification)->create([
            'created_by_user_id' => $admin->id,
            'updated_by_user_id' => $admin->id,
        ]);

        // 行ごとの遅延ロードが 1 件でも発生したら例外になる
        Model::preventLazyLoading();

        $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();
    }

    public function test_each_row_shows_certification_question_count_and_updater(): void
    {
        $admin = User::factory()->admin()->create();
        $updater = User::factory()->admin()->create(['name' => '更新 太郎']);
        $certification = Certification::factory()->published()->create(['name' => '応用情報技術者']);
        $withQuestions = MockExam::factory()->forCertification($certification)->create([
            'title' => '問題あり模試',
            'order' => 1,
            'updated_by_user_id' => $updater->id,
        ]);
        MockExamQuestion::factory()->count(3)->forMockExam($withQuestions)->create();
        MockExam::factory()->forCertification($certification)->create(['title' => '問題なし模試', 'order' => 2]);

        $response = $this->actingAs($admin)->get(route('admin.mock-exams.index'))->assertOk();

        $rows = $response->viewData('mockExams')->keyBy('title');
        $this->assertSame(3, $rows['問題あり模試']->mock_exam_questions_count);
        $this->assertSame(0, $rows['問題なし模試']->mock_exam_questions_count);
        $this->assertTrue($rows['問題あり模試']->relationLoaded('certification'));
        $this->assertTrue($rows['問題あり模試']->relationLoaded('createdBy'));
        $this->assertTrue($rows['問題あり模試']->relationLoaded('updatedBy'));

        $text = preg_replace('/\s+/u', ' ', strip_tags($response->getContent()));
        $this->assertStringContainsString('問題あり模試 応用情報技術者 3', $text);
        $this->assertStringContainsString('更新 太郎', $text);
        $this->assertStringContainsString('問題なし模試 応用情報技術者 0', $text);
    }

    public function test_order_is_certification_then_order_then_latest_update(): void
    {
        $admin = User::factory()->admin()->create();
        $first = Certification::factory()->published()->create();
        $second = Certification::factory()->published()->create();
        [$certA, $certB] = strcmp($first->id, $second->id) < 0 ? [$first, $second] : [$second, $first];

        MockExam::factory()->forCertification($certB)->create(['title' => 'B-1', 'order' => 1]);
        MockExam::factory()->forCertification($certA)->create(['title' => 'A-2', 'order' => 2]);
        MockExam::factory()->forCertification($certA)->create(['title' => 'A-1-old', 'order' => 1, 'updated_at' => now()->subDay()]);
        MockExam::factory()->forCertification($certA)->create(['title' => 'A-1-new', 'order' => 1, 'updated_at' => now()]);

        $titles = $this->actingAs($admin)->get(route('admin.mock-exams.index'))
            ->viewData('mockExams')->pluck('title')->all();

        $this->assertSame(['A-1-new', 'A-1-old', 'A-2', 'B-1'], $titles);
    }

    public function test_coach_still_sees_only_assigned_certification_exams(): void
    {
        $coach = User::factory()->coach()->inProgress()->create();
        $assigned = Certification::factory()->published()->create();
        $other = Certification::factory()->published()->create();
        $assigned->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);
        MockExam::factory()->forCertification($assigned)->create(['title' => '担当資格の模試']);
        MockExam::factory()->forCertification($other)->create(['title' => '担当外の模試']);

        $titles = $this->actingAs($coach)->get(route('admin.mock-exams.index'))
            ->assertOk()
            ->viewData('mockExams')->pluck('title')->all();

        $this->assertSame(['担当資格の模試'], $titles);
    }
}
