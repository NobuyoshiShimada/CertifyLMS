<?php

namespace App\Enums;

/**
 * 質問掲示板（Q&A）における質問スレッドの解決状態を管理する列挙型。
 */
enum QaThreadStatus: string
{
    /** 未解決状態 */
    case Unresolved = 'unresolved';

    /** 解決済状態 */
    case Resolved = 'resolved';

    /**
     * 該当する解決状態に対応する、画面表示用の日本語ラベルを取得する。
     *
     * @return string 日本語のステータス名称（未解決 または 解決済）
     */
    public function label(): string
    {
        return match ($this) {
            self::Unresolved => '未解決',
            self::Resolved => '解決済',
        };
    }
}
