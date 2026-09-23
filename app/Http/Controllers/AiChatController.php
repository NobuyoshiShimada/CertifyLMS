<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\AiChat\DailyMessageLimitExceededException;
use App\Http\Requests\AiChat\StoreConversationRequest;
use App\Http\Requests\AiChat\StoreMessageRequest;
use App\Http\Requests\AiChat\UpdateConversationRequest;
use App\Models\AiChatConversation;
use App\Models\User;
use App\UseCases\AiChat\SendMessageAction;
use App\UseCases\AiChat\StartConversationAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AI 相談(学習中の受講生のみ)。フル画面(HTML)と、ウィジェット / フル画面 JS(JSON)の両方に応答する。
 *
 * 機能 OFF スイッチ時はルート自体を登録しない(404)。API キー未設定時は EnsureAiChatConfigured で 500。
 */
class AiChatController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        /** @var User $student */
        $student = $request->user();

        // 過去の相談をすぐ再開できるよう、直近の会話があればそこへ遷移する
        $latest = $student->aiChatConversations()->orderByDesc('last_message_at')->first();

        return $latest !== null
            ? redirect()->route('ai-chat.conversations.show', $latest)
            : view('ai-chat.empty-state');
    }

    public function store(StoreConversationRequest $request, StartConversationAction $start, SendMessageAction $send): JsonResponse|RedirectResponse
    {
        /** @var User $student */
        $student = $request->user();
        $firstMessage = $request->validated('message');

        $result = $start($student, $request->source(), $request->validated('section_id'), $firstMessage);

        if ($request->expectsJson()) {
            return response()->json(['conversation' => $result->conversation], $result->created ? 201 : 200);
        }

        $redirect = redirect()->route('ai-chat.conversations.show', $result->conversation);

        if ($firstMessage !== null && trim($firstMessage) !== '') {
            try {
                $send($student, $result->conversation, $firstMessage);
            } catch (DailyMessageLimitExceededException $e) {
                return $redirect->with('error', $e->getMessage());
            }
        }

        return $redirect->with('success', '新しい相談を開始しました。');
    }

    public function show(Request $request, AiChatConversation $conversation): View|JsonResponse
    {
        $this->authorize('view', $conversation);

        $conversation->load(['messages', 'enrollment.certification', 'section']);

        if ($request->expectsJson()) {
            return response()->json([
                'conversation' => $conversation->only(['id', 'title', 'section_id', 'enrollment_id', 'last_message_at']),
                'messages' => $conversation->messages->map->only(['id', 'role', 'status', 'content', 'created_at'])->values(),
            ]);
        }

        return view('ai-chat.show', compact('conversation'));
    }

    public function update(UpdateConversationRequest $request, AiChatConversation $conversation): JsonResponse|RedirectResponse
    {
        $conversation->update(['title' => $request->validated('title')]);

        return $request->expectsJson()
            ? response()->json(['conversation' => $conversation->only(['id', 'title'])])
            : redirect()->route('ai-chat.conversations.show', $conversation)->with('success', 'タイトルを変更しました。');
    }

    public function destroy(Request $request, AiChatConversation $conversation): JsonResponse|RedirectResponse
    {
        $this->authorize('delete', $conversation);

        $conversation->delete();

        return $request->expectsJson()
            ? response()->json(null, 204)
            : redirect()->route('ai-chat.index')->with('success', '相談を削除しました。');
    }

    public function storeMessage(StoreMessageRequest $request, AiChatConversation $conversation, SendMessageAction $send): JsonResponse
    {
        /** @var User $student */
        $student = $request->user();

        $result = $send($student, $conversation, $request->validated('content'));

        $payload = [
            'user_message' => $result->userMessage,
            'assistant_message' => $result->assistantMessage->makeHidden(['error_detail', 'input_tokens', 'output_tokens', 'model']),
            'conversation' => $result->conversation->only(['id', 'title', 'last_message_at']),
        ];

        if ($result->failed()) {
            return response()->json([
                'message' => 'AI が応答できませんでした。しばらく時間をおいて再試行してください。',
                'upstream_status' => $result->upstreamStatus,
                ...$payload,
            ], 502);
        }

        return response()->json($payload);
    }
}
