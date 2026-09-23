<?php

declare(strict_types=1);

namespace App\Http\Requests\EnrollmentNote;

use App\Models\EnrollmentNote;
use Illuminate\Foundation\Http\FormRequest;

/**
 * コーチメモの追加入力を検証する(本文: 必須 / 2000 文字以内)。
 */
class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [EnrollmentNote::class, $this->route('enrollment')]) ?? false;
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
