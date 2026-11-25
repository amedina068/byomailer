<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserStorageSetting;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserStorageSetting>
 */
class UserStorageSettingFactory extends Factory
{
    use ResolvesClientFromUser;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => $this->clientOfUser(),
            'provider' => UserStorageSetting::PROVIDER_S3,
            'bucket' => fake()->slug(2),
            'region' => 'us-east-1',
            'endpoint' => null,
            'access_key_id' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_access_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'public_base_url' => 'https://cdn.example.com',
            'path_prefix' => 'byomailer/{user_id}',
            'is_verified' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
        ]);
    }

    public function r2(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => UserStorageSetting::PROVIDER_R2,
            'endpoint' => 'https://accountid.r2.cloudflarestorage.com',
            'region' => 'auto',
        ]);
    }
}
