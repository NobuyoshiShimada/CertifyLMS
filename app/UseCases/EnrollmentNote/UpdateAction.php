<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\EnrollmentNote;

/**
 * コーチメモの本文を更新するユースケース。作成者は書き換えない(管理者が越境編集しても元の作成者のまま)。
 */
final class UpdateAction
{
    public function __invoke(EnrollmentNote $note, string $body): EnrollmentNote
    {
        $note->update(['body' => $body]);

        return $note;
    }
}
