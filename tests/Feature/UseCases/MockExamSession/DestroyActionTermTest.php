<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\MockExamSession;

use App\Enums\TermType;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\UseCases\MockExamSession\DestroyAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B-A-03 回帰テスト: 受験セッションのキャンセル後、キャンセル済みセッションは「進行中の模試」とみなさずにタームを再判定する。
 */
class DestroyActionTermTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancel_keeps_basic_learning_even_with_past_canceled_sessions(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'canceled']);
        $session = MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'not_started']);

        (app(DestroyAction::class))($session);

        $this->assertSame(TermType::BasicLearning, $enrollment->refresh()->current_term);
    }

    public function test_cancel_returns_mock_practice_enrollment_to_basic_learning_when_no_active_session_remains(): void
    {
        // 旧ロジックで実践タームに誤判定されたままの受講登録も、次のキャンセルで基礎タームへ戻る
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'canceled']);
        $session = MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'not_started']);

        (app(DestroyAction::class))($session);

        $this->assertSame(TermType::BasicLearning, $enrollment->refresh()->current_term);
    }

    public function test_cancel_keeps_mock_practice_while_graded_session_remains(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'graded']);
        $session = MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'not_started']);

        (app(DestroyAction::class))($session);

        $this->assertSame(TermType::MockPractice, $enrollment->refresh()->current_term);
    }

    public function test_cancel_does_not_affect_other_enrollment_term(): void
    {
        $enrollment = Enrollment::factory()->create(['current_term' => TermType::BasicLearning->value]);
        $other = Enrollment::factory()->create(['current_term' => TermType::MockPractice->value]);
        $exam = MockExam::factory()->for($enrollment->certification)->create();
        $session = MockExamSession::factory()->for($enrollment)->for($exam)->create(['status' => 'not_started']);

        (app(DestroyAction::class))($session);

        $this->assertSame(TermType::MockPractice, $other->refresh()->current_term);
    }
}
