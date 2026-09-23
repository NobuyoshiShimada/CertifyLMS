<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentNote>
 */
class EnrollmentNoteFactory extends Factory
{
    protected $model = EnrollmentNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'author_user_id' => User::factory()->coach(),
            'body' => fake()->randomElement([
                '最近 chat の応答が遅れている。次回面談で学習時間を確認する。',
                'Q&A でネットワーク分野の用語に躓いていた。補足資料を案内済み。',
                '模試の点数が安定してきた。過去問演習へ移行を提案。',
                '次回面談で試験日の再設定について相談したい。',
            ]),
        ];
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn () => ['enrollment_id' => $enrollment->id]);
    }

    public function byAuthor(User $author): static
    {
        return $this->state(fn () => ['author_user_id' => $author->id]);
    }
}
