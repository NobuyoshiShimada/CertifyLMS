<?php

declare(strict_types=1);

namespace App\UseCases\EnrollmentNote;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;

/**
 * 受講登録にコーチメモを追加するユースケース。操作者(コーチ / 管理者)を作成者として記録する。
 */
final class StoreAction
{
    public function __invoke(Enrollment $enrollment, User $author, string $body): EnrollmentNote
    {
        return EnrollmentNote::create([
            'enrollment_id' => $enrollment->id,
            'author_user_id' => $author->id,
            'body' => $body,
        ]);
    }
}
