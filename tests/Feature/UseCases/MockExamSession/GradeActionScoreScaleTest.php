<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MockExamSession;

use App\Models\MockExam;
use App\Models\MockExamAnswer;
use App\Models\MockExamQuestion;
use App\Models\MockExamSession;
use App\UseCases\MockExamSession\GradeAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * B-A-02 回帰テスト: 得点率は 0〜100 の百分率(小数第 2 位)で保存され、合格基準点以上で合格となる。
 */
class GradeActionScoreScaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{int, int, int, float, bool}>
     */
    public static function scoreCases(): array
    {
        return [
            '4/5 は 80.00 で合格' => [5, 4, 60, 80.00, true],
            '5/5 は 100.00 で合格' => [5, 5, 60, 100.00, true],
            '3/5 は合格基準点ぴったりで合格' => [5, 3, 60, 60.00, true],
            '2/5 は 40.00 で不合格' => [5, 2, 60, 40.00, false],
            '全問不正解は 0.00 で不合格' => [5, 0, 60, 0.00, false],
            '2/3 は小数第 2 位で四捨五入' => [3, 2, 60, 66.67, true],
        ];
    }

    #[DataProvider('scoreCases')]
    public function test_score_percentage_is_saved_on_0_to_100_scale(int $total, int $correct, int $passingScore, float $expected, bool $expectedPass): void
    {
        $mockExam = MockExam::factory()->published()->passingScore($passingScore)->create();
        $questions = collect();
        for ($i = 0; $i < $total; $i++) {
            $questions->push(MockExamQuestion::factory()->forMockExam($mockExam)->withOptions(4, 0)->create(['order' => $i]));
        }

        $session = MockExamSession::factory()
            ->forMockExam($mockExam)
            ->inProgress()
            ->create([
                'generated_question_ids' => $questions->pluck('id')->all(),
                'total_questions' => $total,
                'passing_score_snapshot' => $passingScore,
            ]);

        foreach ($questions as $index => $question) {
            $selected = $question->options->firstWhere('is_correct', $index < $correct);
            MockExamAnswer::factory()->create([
                'mock_exam_session_id' => $session->id,
                'mock_exam_question_id' => $question->id,
                'selected_option_id' => $selected->id,
                'selected_option_body' => $selected->body,
                'is_correct' => false,
                'answered_at' => now(),
            ]);
        }

        (app(GradeAction::class))($session);

        $session->refresh();
        $this->assertSame($correct, $session->total_correct);
        $this->assertEquals($expected, (float) $session->score_percentage);
        $this->assertSame($expectedPass, $session->pass);
    }

    public function test_zero_question_session_is_graded_as_zero_and_fail(): void
    {
        $mockExam = MockExam::factory()->published()->passingScore(60)->create();
        $session = MockExamSession::factory()
            ->forMockExam($mockExam)
            ->inProgress()
            ->create([
                'generated_question_ids' => [],
                'total_questions' => 0,
                'passing_score_snapshot' => 60,
            ]);

        (app(GradeAction::class))($session);

        $session->refresh();
        $this->assertEquals(0.00, (float) $session->score_percentage);
        $this->assertFalse($session->pass);
    }
}
