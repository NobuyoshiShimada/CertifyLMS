<?php

declare(strict_types=1);

namespace App\Exceptions\AiChat;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 受講登録(学習中 / 修了)のない資格の教材(Section)に紐づく会話を作ろうとした場合の例外(403)。
 */
final class SectionNotAccessibleException extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct('この教材の AI 相談は、受講中または修了済みの資格でのみ利用できます。');
    }
}
