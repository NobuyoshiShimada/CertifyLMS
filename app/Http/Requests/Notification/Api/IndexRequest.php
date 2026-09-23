<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 通知ポップオーバー用 一覧 API のクエリ検証。違反時は 422 + JSON(日本語)で返る。
 *
 * - tab: 「全件」/「未読のみ」(省略時は全件)
 * - per_page: 1〜50(ポップオーバー用途のため上限は小さめ、省略時は 20)
 */
class IndexRequest extends FormRequest
{
    public const TAB_ALL = '全件';

    public const TAB_UNREAD = '未読のみ';

    public const DEFAULT_PER_PAGE = 20;

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
            'tab' => ['nullable', 'string', 'in:'.self::TAB_ALL.','.self::TAB_UNREAD],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tab' => 'タブ',
            'per_page' => '表示件数',
        ];
    }

    public function onlyUnread(): bool
    {
        return $this->validated('tab') === self::TAB_UNREAD;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
