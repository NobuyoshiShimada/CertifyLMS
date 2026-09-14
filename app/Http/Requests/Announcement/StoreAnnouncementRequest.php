<?php

declare(strict_types=1);

namespace App\Http\Requests\Announcement;

use App\Enums\AnnouncementTargetType;
use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    /**
     * リクエストに適用するバリデーションルールを定義する。
     *
     * @return array<string, array<int, mixed>> バリデーションルールの配列
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'target_type' => ['required', Rule::enum(AnnouncementTargetType::class)],
            'target_certification_id' => [
                'nullable',
                'required_if:target_type,'.AnnouncementTargetType::Certification->value,
                Rule::exists('certifications', 'id')->where('status', CertificationStatus::Published->value),
            ],
            'target_user_id' => [
                'nullable',
                'required_if:target_type,'.AnnouncementTargetType::User->value,
                Rule::exists('users', 'id')->where('role', UserRole::Student->value),
            ],
        ];
    }

    /**
     * バリデーションエラーメッセージの属性名（項目名）のフレンドリーな別名を定義する。
     *
     * @return array<string, string> 属性名と日本語名称のペア配列
     */
    public function attributes(): array
    {
        return [
            'title' => 'タイトル',
            'body' => '本文',
            'target_type' => '配信対象の種類',
            'target_certification_id' => '対象資格',
            'target_user_id' => '対象ユーザー',
        ];
    }
}
