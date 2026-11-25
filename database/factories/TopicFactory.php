<?php

namespace Database\Factories;

use App\Models\MailList;
use App\Models\Topic;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Topic>
 */
class TopicFactory extends Factory
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
            'list_id' => fn (array $a) => MailList::factory()->create(['user_id' => $a['user_id'], 'client_id' => $a['client_id']])->id,
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
