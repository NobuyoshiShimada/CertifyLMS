<?php

declare(strict_types=1);

namespace Tests\Unit\Requests;

use App\Http\Requests\QaBoard\UpdateQaThreadRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * UpdateQaThreadRequest のバリデーションルールおよび日本語属性名を検証する単体テスト。
 */
class UpdateQaThreadRequestTest extends TestCase
{
    /**
     * 必須項目（タイトル、本文）が空のときに、バリデーションが正しく失敗することを検証する。
     *
     * @return void
     */
    public function test_validation_fails_with_empty_data(): void
    {
        $request = new UpdateQaThreadRequest;
        $rules = $request->rules();

        $validator = Validator::make([
            'title' => '',
            'body' => '',
        ], $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    /**
     * 文字数制限（タイトル200文字、本文5000文字）を超えた入力の際、バリデーションが正しく失敗することを検証する。
     *
     * @return void
     */
    public function test_validation_fails_when_character_lengths_exceed_limits(): void
    {
        $request = new UpdateQaThreadRequest;
        $rules = $request->rules();

        $validator = Validator::make([
            'title' => str_repeat('あ', 201),   // 200文字制限超過
            'body' => str_repeat('い', 5001),  // 5000文字制限超過
        ], $rules);

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
        $this->assertArrayHasKey('body', $validator->errors()->toArray());
    }

    /**
     * attributes メソッドで指定した日本語の項目名が寸分違わず正しく定義されていることを検証する。
     *
     * @return void
     */
    public function test_attributes_returns_correct_japanese_labels(): void
    {
        $request = new UpdateQaThreadRequest;
        $attributes = $request->attributes();

        $this->assertEquals('タイトル', $attributes['title']);
        $this->assertEquals('本文', $attributes['body']);
    }
}
