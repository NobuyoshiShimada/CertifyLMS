<?php

declare(strict_types=1);

namespace App\Http\Requests\MeetingQuota;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 追加面談パックの購入開始リクエスト。パックの公開状態の判定は StartCheckoutAction で行う(非公開は 422)。
 */
class StartCheckoutRequest extends FormRequest
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
            'meeting_pack_id' => ['required', 'string', 'exists:meeting_packs,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'meeting_pack_id' => '面談パック',
        ];
    }
}
