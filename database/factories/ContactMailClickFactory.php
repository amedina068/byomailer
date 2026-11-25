<?php

namespace Database\Factories;

use App\Models\ContactMail;
use App\Models\ContactMailClick;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<ContactMailClick>
 */
class ContactMailClickFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_mail_id' => ContactMail::factory(),
            'contact_id' => fn (array $a) => DB::table('contact_mails')->where('id', $a['contact_mail_id'])->value('contact_id'),
            'link' => fake()->url(),
            'clicked_at' => now(),
        ];
    }
}
