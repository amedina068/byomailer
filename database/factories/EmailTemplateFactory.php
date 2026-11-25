<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
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
            'name' => fake()->words(3, true),
            'html_content' => '<p>'.fake()->paragraph().'</p><p>{{ content }}</p>',
            'is_default' => false,
        ];
    }

    public function system(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'client_id' => null,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }
}
