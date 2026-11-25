<?php

namespace Database\Factories;

use App\Models\EmailIdentity;
use App\Models\EmailIdentityDnsRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailIdentityDnsRecord>
 */
class EmailIdentityDnsRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email_identity_id' => EmailIdentity::factory(),
            'type' => fake()->randomElement(['CNAME', 'MX', 'TXT']),
            'name' => fake()->domainWord().'.example.com',
            'value' => fake()->domainName(),
            'priority' => null,
            'verified_at' => null,
        ];
    }
}
