<?php

namespace Database\Factories;

use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 回答（QaReply）のテストデータを生成するファクトリ。
 */
class QaReplyFactory extends Factory
{
    /**
     * 回答のデフォルトの状態（カラム値）を定義する。
     *
     * @return array<string, mixed> 生成されるデータの配列
     */
    public function definition(): array
    {
        return [
            'qa_thread_id' => QaThread::factory(),
            'user_id'      => User::factory(),
            'body'         => $this->faker->realText(150),
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
    }
}
