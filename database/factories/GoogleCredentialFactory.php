<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GoogleCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleCredential>
 */
class GoogleCredentialFactory extends Factory
{
    protected $model = GoogleCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->coach()->inProgress(),
            'access_token' => 'ya29.test-'.Str::random(32),
            'refresh_token' => '1//test-'.Str::random(32),
            'token_expires_at' => now()->addHour(),
            'calendar_id' => GoogleCredential::PRIMARY_CALENDAR,
            'connected_at' => now(),
        ];
    }

    public function forCoach(User $coach): static
    {
        return $this->state(fn () => ['user_id' => $coach->id]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subMinutes(5)]);
    }
}
