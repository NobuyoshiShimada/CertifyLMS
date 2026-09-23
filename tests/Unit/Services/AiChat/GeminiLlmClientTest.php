<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AiChat;

use App\Services\AiChat\GeminiLlmClient;
use App\Services\AiChat\LlmRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Gemini API クライアントを Http::fake で検証する(GeminiLlmClient は Laravel の Http Facade で通信するため、Facade のフェイクで完全に遮断できる)。
 */
#[Group('external')]
class GeminiLlmClientTest extends TestCase
{
    private const ENDPOINT = 'https://gemini.test/v1beta/models/gemini-test:generateContent';

    protected function setUp(): void
    {
        parent::setUp();

        // 再試行の待機を実際に眠らせない
        Sleep::fake();
    }

    private function client(): GeminiLlmClient
    {
        return new GeminiLlmClient('test-api-key', 'gemini-test', 'https://gemini.test/v1beta/', 10);
    }

    /**
     * @param list<array{text: string}> $parts
     *
     * @return array<string, mixed>
     */
    private static function success(array $parts = [['text' => '回答です。']]): array
    {
        return [
            'candidates' => [['content' => ['parts' => $parts], 'finishReason' => 'STOP']],
            'modelVersion' => 'gemini-test-001',
            'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 5],
        ];
    }

    public function test_returns_response_text_model_and_token_usage(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::success([['text' => '前半'], ['text' => '後半']]))]);

        $response = $this->client()->generate('system', [['role' => 'user', 'content' => '質問']]);

        $this->assertSame('前半後半', $response->content);
        $this->assertSame('gemini-test-001', $response->model);
        $this->assertSame(12, $response->inputTokens);
        $this->assertSame(5, $response->outputTokens);
        Http::assertSentCount(1);
    }

    public function test_sends_system_instruction_and_history_with_gemini_roles(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::success())]);

        $this->client()->generate('あなたは資格学習のアシスタントです。', [
            ['role' => 'user', 'content' => '最初の質問'],
            ['role' => 'assistant', 'content' => '最初の回答'],
            ['role' => 'user', 'content' => '次の質問'],
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->url() === self::ENDPOINT
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $request['systemInstruction'] === ['parts' => [['text' => 'あなたは資格学習のアシスタントです。']]]
                && $request['contents'] === [
                    ['role' => 'user', 'parts' => [['text' => '最初の質問']]],
                    ['role' => 'model', 'parts' => [['text' => '最初の回答']]],
                    ['role' => 'user', 'parts' => [['text' => '次の質問']]],
                ];
        });
    }

    /**
     * @return array<string, array{int}>
     */
    public static function transientStatuses(): array
    {
        return ['429 レート制限' => [429], '500' => [500], '503' => [503]];
    }

    #[DataProvider('transientStatuses')]
    public function test_retries_once_after_transient_error_and_succeeds(int $status): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push(['error' => ['message' => 'temporary']], $status)
            ->push(self::success())]);

        $response = $this->client()->generate('system', [['role' => 'user', 'content' => '質問']]);

        $this->assertSame('回答です。', $response->content);
        Http::assertSentCount(2);
    }

    public function test_retries_after_connection_error_and_succeeds(): void
    {
        $attempt = 0;
        Http::fake([self::ENDPOINT => function () use (&$attempt) {
            if (++$attempt === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response(self::success());
        }]);

        $this->assertSame('回答です。', $this->client()->generate('system', [])->content);
        $this->assertSame(2, $attempt);
    }

    public function test_throws_after_transient_error_persists_beyond_max_attempts(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'overloaded']], 503)]);

        try {
            $this->client()->generate('system', []);
            $this->fail('LlmRequestException が投げられるはず');
        } catch (LlmRequestException $e) {
            $this->assertSame(503, $e->upstreamStatus);
        }

        Http::assertSentCount(2);
    }

    public function test_connection_error_on_every_attempt_is_converted_to_llm_exception(): void
    {
        Http::fake([self::ENDPOINT => fn () => throw new ConnectionException('unreachable')]);

        $this->expectException(LlmRequestException::class);
        $this->expectExceptionMessage('Gemini API に接続できませんでした');

        $this->client()->generate('system', []);
    }

    public function test_client_error_is_not_retried(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        try {
            $this->client()->generate('system', []);
            $this->fail('LlmRequestException が投げられるはず');
        } catch (LlmRequestException $e) {
            $this->assertSame(400, $e->upstreamStatus);
        }

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function emptyBodies(): array
    {
        return [
            '候補なし' => [['candidates' => []]],
            '空文字のみ' => [['candidates' => [['content' => ['parts' => [['text' => '  ']]], 'finishReason' => 'STOP']]]],
            '安全フィルタで本文なし' => [['candidates' => [['finishReason' => 'SAFETY']]]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('emptyBodies')]
    public function test_empty_response_body_throws_llm_exception(array $body): void
    {
        Http::fake([self::ENDPOINT => Http::response($body)]);

        $this->expectException(LlmRequestException::class);
        $this->expectExceptionMessage('応答本文が得られませんでした');

        $this->client()->generate('system', []);
    }

    public function test_unfaked_request_is_blocked_instead_of_reaching_real_api(): void
    {
        // 別 URL だけをフェイクした状態で呼ぶと、preventStrayRequests により実通信せず例外になる
        Http::fake(['https://other.test/*' => Http::response()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Attempted request to');

        $this->client()->generate('system', []);
    }
}
