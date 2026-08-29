<?php

namespace Database\Factories;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 質問スレッド（QaThread）のテストデータを生成するファクトリ。
 */
class QaThreadFactory extends Factory
{
    /**
     * 質問スレッドのデフォルトの状態（カラム値）を定義する。
     *
     * @return array<string, mixed> 生成されるデータの配列
     */
    public function definition(): array
    {
        $isResolved = $this->faker->boolean(40); // 40%の確率で解決済
        $createdAt = $this->faker->dateTimeBetween('-3 weeks', 'now');

        return [
            'user_id'          => User::factory(),
            'certification_id' => Certification::factory(),
            'title'            => '質問のタイトルがここに設定されます', // シーダー側で上書き
            'body'             => '質問の具体的な本文がここに設定されます', // シーダー側で上書き
            'status'           => $isResolved ? QaThreadStatus::Resolved : QaThreadStatus::Unresolved,
            'resolved_at'      => $isResolved ? $this->faker->dateTimeBetween($createdAt, 'now') : null,
            'created_at'       => $createdAt,
            'updated_at'       => $this->faker->dateTimeBetween($createdAt, 'now'),
        ];
    }
}
