<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EnrollmentNote\StoreRequest;
use App\Http\Requests\EnrollmentNote\UpdateRequest;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\UseCases\EnrollmentNote\StoreAction;
use App\UseCases\EnrollmentNote\UpdateAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * コーチメモ(担当コーチ / 管理者のみ)。一覧と追加フォームは受講登録詳細画面に埋め込み、編集は専用ページ。
 */
class EnrollmentNoteController extends Controller
{
    public function store(Enrollment $enrollment, StoreRequest $request, StoreAction $action): RedirectResponse
    {
        $action($enrollment, $request->user(), $request->validated('body'));

        return redirect()
            ->route('enrollments.show', $enrollment)
            ->with('success', 'メモを追加しました。');
    }

    public function edit(EnrollmentNote $note): View
    {
        $this->authorize('update', $note);

        return view('enrollment-note.edit', compact('note'));
    }

    public function update(EnrollmentNote $note, UpdateRequest $request, UpdateAction $action): RedirectResponse
    {
        $action($note, $request->validated('body'));

        return redirect()
            ->route('enrollments.show', $note->enrollment_id)
            ->with('success', 'メモを更新しました。');
    }

    public function destroy(EnrollmentNote $note): RedirectResponse
    {
        $this->authorize('delete', $note);

        $note->delete();

        return redirect()
            ->route('enrollments.show', $note->enrollment_id)
            ->with('success', 'メモを削除しました。');
    }
}
