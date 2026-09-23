<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Services\AiChat\AiChatLlmClient;
use App\Services\AiChat\LlmRequestException;
use App\Services\AiChat\LlmResponse;

/**
 * テスト用の AiChatLlmClient。実 API には接続せず、受け取ったシステム指示 / 履歴を記録し、失敗を任意に起こせる。
 */
class FakeAiChatLlmClient implements AiChatLlmClient
{
    /** @var list<array{system: string, history: list<array{role: string, content: string}>}> */
    public array $calls = [];

    public bool $configured = true;

    /** 応答生成(1 回目の呼び出し)を失敗させる上流 HTTP ステータス。null なら成功 */
    public ?int $failWithStatus = null;

    public bool $failTitle = false;

    public string $reply = 'まずは過去問を 1 年分解いて、苦手分野を洗い出しましょう。';

    public string $title = '過去問の進め方';

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function generate(string $systemInstruction, array $history): LlmResponse
    {
        $this->calls[] = ['system' => $systemInstruction, 'history' => $history];

        $isTitleRequest = str_contains($systemInstruction, 'タイトルを');
        if ($isTitleRequest) {
            if ($this->failTitle) {
                throw new LlmRequestException('title failed', 500);
            }

            return new LlmResponse($this->title, 'fake-model', 10, 5);
        }

        if ($this->failWithStatus !== null) {
            throw new LlmRequestException("Gemini API HTTP {$this->failWithStatus}", $this->failWithStatus);
        }

        return new LlmResponse($this->reply, 'fake-model', 123, 45);
    }

    /**
     * タイトル生成を除いた、応答生成の呼び出し。
     *
     * @return list<array{system: string, history: list<array{role: string, content: string}>}>
     */
    public function replyCalls(): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => ! str_contains($call['system'], 'タイトルを')));
    }
}
