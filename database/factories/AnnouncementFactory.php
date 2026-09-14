<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnnouncementTargetType;
use App\Enums\UserRole;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(6),
            'body' => fake()->paragraph(3),
            'target_type' => AnnouncementTargetType::AllStudents->value,
            'target_certification_id' => null,
            'target_user_id' => null,
            'dispatched_count' => fake()->numberBetween(0, 20),
            'dispatched_at' => now(),
            'created_by' => User::factory()->admin(),
        ];
    }

    public function forCertification(string $certificationId): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::Certification->value,
            'target_certification_id' => $certificationId,
            'target_user_id' => null,
        ]);
    }

    public function forUser(string $userId): static
    {
        return $this->state(fn () => [
            'target_type' => AnnouncementTargetType::User->value,
            'target_certification_id' => null,
            'target_user_id' => $userId,
        ]);
    }
}
