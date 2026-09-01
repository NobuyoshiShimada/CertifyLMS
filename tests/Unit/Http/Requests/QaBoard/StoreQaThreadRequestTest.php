<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\QaBoard\StoreQaThreadRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * StoreQaThreadRequest のバリデーションルールおよび属性名を検証する単体テスト。
 */
class QaThreadRequestTest extends TestCase
{
    /**
     * 必須項目が空のときにバリデーションが正しく失敗することを検証する。
     *
     * @return void
     */
    public function test_validation_fails_with_empty_data(): void
    {
        $request = new StoreQaThreadRequest;
        $rules = $request->rules();

        $validator = Validator::make([
            'certification_id' => '',
            'title' => '',
            'body' => '',
        ], $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
    }

    /**
     * attributes メソッドで指定した日本語項目名が正しく取得できることを検証する。
     *
     * @return void
     */
    public function test_attributes_returns_japanese_labels(): void
    {
        $request = new StoreQaThreadRequest;
        $attributes = $request->attributes();

        $this->assertEquals('タイトル', $attributes['title']);
        $this->assertEquals('本文', $attributes['body']);
    }
}
