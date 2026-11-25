<?php

namespace Database\Factories;

use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    use ResolvesClientFromUser;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => $this->clientOfUser(),
            'email_identity_id' => EmailIdentity::factory(),
            'subject' => fake()->sentence(),
            'from_email' => fake()->companyEmail(),
            'from_name' => fake()->company(),
            'markdown_content' => '# '.fake()->sentence(),
            'html_content' => '<p>'.fake()->sentence().'</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'text_content' => fake()->sentence(),
        ];
    }
}
