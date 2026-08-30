<?php

namespace App\Http\Requests\QaBoard;

use Illuminate\Foundation\Http\FormRequest;

class StoreQaReplyRequest extends FormRequest
{
    /**
     * リクエストの実行ユーザーに権限があるかどうかを判定する。
     *
     * @return bool 常に true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * リクエストに適用するバリデーションルールを定義する.
     *
     * @return array<string, array<int, string>|string> バリデーションルールの配列
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
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
            'body' => '回答本文',
        ];
    }
}
