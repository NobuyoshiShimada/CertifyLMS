<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Stripe からのサーバ間 POST(ブラウザのセッション / CSRF トークンを持たない)。正当性は署名検証で担保する
        'webhooks/stripe',
    ];
}
