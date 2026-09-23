<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use Illuminate\Foundation\Http\FormRequest;

/**
 * コーチメモの本文更新を検証する。作成者は受け付けない(編集しても作成者は不変)。
 */
class UpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('note')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'body' => 'メモ本文',
        ];
    }
}
