<?php

namespace Database\Factories;

use App\Models\Newsletter;
use App\Models\TestMail;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestMail>
 */
class TestMailFactory extends Factory
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
            'mailable_type' => Newsletter::class,
            'mailable_id' => fn (array $a) => Newsletter::factory()->create(['user_id' => $a['user_id']])->id,
            'sent_at' => now(),
        ];
    }
}
