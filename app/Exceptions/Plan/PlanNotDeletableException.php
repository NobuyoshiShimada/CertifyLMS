<?php

declare(strict_types=1);

namespace App\Exceptions\Plan;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * プランを削除できない場合の例外(409)。
 *
 * 削除できるのは「下書き状態 かつ 受講者が 1 名も紐づいていない」プランのみ。
 */
final class PlanNotDeletableException extends ConflictHttpException
{
    public static function notDraft(): self
    {
        return new self('下書き状態のプランのみ削除できます。先に下書きに戻すか、アーカイブを利用してください。');
    }

    public static function hasUsers(): self
    {
        return new self('このプランは受講者が紐づいているため削除できません。');
    }

    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
