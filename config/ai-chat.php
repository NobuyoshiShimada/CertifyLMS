<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| AI 相談(Gemini チャットボット)
|--------------------------------------------------------------------------
|
| 値はすべて .env で上書きできる。API キーは必ず .env(GEMINI_API_KEY)で設定し、コードに直接書かない。
|
*/

return [
    // 機能全体の ON / OFF。OFF の場合は AI 相談ルートを登録せず(404)、サイドバー項目・ウィジェットも表示しない
    'enabled' => (bool) env('AI_CHAT_ENABLED', true),

    // 受講生 1 人あたりの 1 日の送信上限(失敗した送信も数える)
    'daily_message_limit' => (int) env('AI_CHAT_DAILY_MESSAGE_LIMIT', 50),

    // AI へ引き渡す直近の会話履歴の件数(エラー状態のメッセージは除外)
    'history_limit' => (int) env('AI_CHAT_HISTORY_LIMIT', 20),

    // 最初の AI 応答完了時に会話タイトルを AI で自動生成するか
    'title_generation_enabled' => (bool) env('AI_CHAT_TITLE_GENERATION_ENABLED', true),

    // 会話作成時の暫定タイトル
    'default_title' => '新規相談',

    'system_prompt' => env('AI_CHAT_SYSTEM_PROMPT', implode("\n", [
        'あなたは資格取得を目指す受講生の学習をサポートする AI アシスタントです。',
        '日本語で、受講生のレベルに合わせて簡潔かつ正確に回答してください。',
        '確信のない内容は推測である旨を明示し、必要に応じて担当コーチへの相談を勧めてください。',
    ])),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
    ],
];
