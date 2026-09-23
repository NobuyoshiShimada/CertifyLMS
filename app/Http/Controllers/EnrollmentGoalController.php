<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentGoal\StoreRequest;
use App\Http\Requests\EnrollmentGoal\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentGoal;
use App\UseCases\EnrollmentGoal\MarkAchievedAction;
use App\UseCases\EnrollmentGoal\StoreAction;
use App\UseCases\EnrollmentGoal\UnmarkAchievedAction;
use App\UseCases\EnrollmentGoal\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * 個人目標(受講生本人のみ操作)。一覧と追加フォームは受講登録詳細画面に埋め込み、単独の一覧画面は持たない。
 */
class EnrollmentGoalController extends Controller
{
    public function store(Enrollment $enrollment, StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $action($enrollment, $request->validated());

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', '目標を追加しました。');
    }

    public function edit(EnrollmentGoal $goal): View
    {
        $this->authorize('update', $goal);

        return view('enrollment-goal.edit', compact('goal'));
    }

    public function update(EnrollmentGoal $goal, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($goal, $request->validated());

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を更新しました。');
    }

    public function destroy(EnrollmentGoal $goal): RedirectResponse
    {
        $this->authorize('delete', $goal);

        $goal->delete();

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を削除しました。');
    }

    public function markAchieved(EnrollmentGoal $goal, MarkAchievedAction $action): RedirectResponse
    {
        $this->authorize('markAchieved', $goal);

        $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を達成済にしました。');
    }

    public function unmarkAchieved(EnrollmentGoal $goal, UnmarkAchievedAction $action): RedirectResponse
    {
        $this->authorize('unmarkAchieved', $goal);

        $action($goal);

        return redirect()
            ->route('enrollments.show', $goal->enrollment_id)
            ->with('success', '目標を未達成に戻しました。');
    }
}
