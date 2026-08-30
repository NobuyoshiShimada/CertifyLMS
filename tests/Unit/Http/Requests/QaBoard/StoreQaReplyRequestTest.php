<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\QaBoard\StoreQaReplyRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * StoreQaReplyRequest のバリデーションルールおよび日本語属性名を検証する単体テスト。
 */
class StoreQaReplyRequestTest extends TestCase
{
    /**
     * 回答本文が空のときに、バリデーションが正しく失敗することを検証する。
     *
     * @return void
     */
    public function test_validation_fails_when_body_is_empty(): void
    {
        $request = new StoreQaReplyRequest();
        $rules = $request->rules();

        $validator = Validator::make([
            'body' => '',
        ], $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    /**
     * 回答本文が5000文字を超えた際に、バリデーションが正しく失敗することを検証する。
     *
     * @return void
     */
    public function test_validation_fails_when_body_exceeds_character_limit(): void
    {
        $request = new StoreQaReplyRequest();
        $rules = $request->rules();

        $validator = Validator::make([
            'body' => str_repeat('答', 5001), // 5000文字制限超過
        ], $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    /**
     * attributes メソッドで指定したエラーメッセージ用の属性名が「回答本文」になっていることを検証する。
     *
     * @return void
     */
    public function test_attributes_returns_friendly_japanese_label(): void
    {
        $request = new StoreQaReplyRequest();
        $attributes = $request->attributes();

        $this->assertEquals('回答本文', $attributes['body']);
    }
}
