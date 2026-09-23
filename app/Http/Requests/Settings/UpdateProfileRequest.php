<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 本人のプロフィール(氏名 / 自己紹介 / コーチのみ固定面談 URL)の更新入力を検証する。
 *
 * メールは受け付けない。固定面談 URL はコーチ以外から送られても検証せず除外する(エラーにせず無視)。
 * 失敗時はプロフィールタブへ戻す。
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'meeting_url' => [
                Rule::excludeIf($this->user()?->role !== UserRole::Coach),
                'nullable',
                'url',
                'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => '氏名',
            'bio' => '自己紹介',
            'meeting_url' => '固定面談 URL',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('settings.profile.edit', ['tab' => 'profile']);
    }
}
