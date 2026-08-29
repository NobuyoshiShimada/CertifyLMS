<?php

namespace App\Http\Requests\QaBoad;

use Illuminate\Foundation\Http\FormRequest;

class StoreQaThreadRequest extends FormRequest
{
/**
     * リクエストに適用するバリデーションルールを定義する。
     *
     * @return array<string, array<int, string>|string> バリデーションルールの配列
     */
    public function rules(): array
    {
        return [
            'certification_id' => ['required', 'exists:certifications,id'],
            'title'            => ['required', 'string', 'max:200'],
            'body'             => ['required', 'string', 'max:5000'],
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
            'certification_id' => '資格',
            'title'            => 'タイトル',
            'body'             => '本文',
        ];
    }
}
