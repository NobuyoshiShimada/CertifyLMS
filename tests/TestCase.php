<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Http Facade 経由の未モック通信はテスト失敗にする(API キー漏洩・利用枠消費・不安定な失敗の防止)。
        // Google SDK / Stripe SDK は独自の HTTP クライアントを使うため、これとは別に窓口クラスの差し替えで遮断する。
        Http::preventStrayRequests();
    }
}
