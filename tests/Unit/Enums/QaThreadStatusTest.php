<?php

namespace Tests\Unit\Enums;

use App\Enums\QaThreadStatus;
use Tests\TestCase;

/**
 * QaThreadStatus 列挙型の日本語ラベル変換ロジックを検証する単体テスト。
 */
class QaThreadStatusTest extends TestCase
{
    /**
     * 各Enum値に対応する label メソッドの戻り値が正しい日本語表記になっていることを検証する。
     *
     * @return void
     */
    public function test_label_returns_correct_japanese_string(): void
    {
        $this->assertEquals('未解決', QaThreadStatus::Unresolved->label());
        $this->assertEquals('解決済', QaThreadStatus::Resolved->label());
    }
}
