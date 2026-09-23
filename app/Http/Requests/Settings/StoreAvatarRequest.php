<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * アバター画像のアップロードを検証する(PNG / JPEG / WebP、2MB 以下)。失敗時はプロフィールタブへ戻す。
 */
class StoreAvatarRequest extends FormRequest
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
            'avatar' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'avatar' => 'アイコン画像',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('settings.profile.edit', ['tab' => 'profile']);
    }
}
