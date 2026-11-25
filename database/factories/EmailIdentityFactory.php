<?php

namespace Database\Factories;

use App\Enums\DnsRecordPurpose;
use App\Enums\IdentityStatus;
use App\Models\EmailIdentity;
use App\Models\User;
use Database\Factories\Concerns\ResolvesClientFromUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailIdentity>
 */
class EmailIdentityFactory extends Factory
{
    use ResolvesClientFromUser;

    public function configure(): static
    {
        return $this->afterCreating(function (EmailIdentity $identity): void {
            if (! $identity->domain) {
                return;
            }

            $records = [];

            $tokens = collect(range(1, 3))->map(fn () => fake()->regexify('[a-z0-9]{16}'));

            foreach ($tokens as $token) {
                $records[] = [
                    'type' => 'CNAME',
                    'name' => "{$token}._domainkey.{$identity->domain}",
                    'value' => "{$token}.dkim.amazonses.com",
                    'purpose' => DnsRecordPurpose::Dkim,
                ];
            }

            if ($identity->mail_from_subdomain && $identity->region) {
                $records[] = [
                    'type' => 'MX',
                    'name' => $identity->mail_from_subdomain,
                    'value' => "feedback-smtp.{$identity->region}.amazonses.com",
                    'priority' => 10,
                    'purpose' => DnsRecordPurpose::MailFrom,
                ];

                $records[] = [
                    'type' => 'TXT',
                    'name' => $identity->mail_from_subdomain,
                    'value' => 'v=spf1 include:amazonses.com ~all',
                    'purpose' => DnsRecordPurpose::MailFrom,
                ];
            }

            $records[] = [
                'type' => 'TXT',
                'name' => "_dmarc.{$identity->domain}",
                'value' => 'v=DMARC1; p=none;',
                'purpose' => DnsRecordPurpose::Dmarc,
            ];

            $identity->dnsRecords()->createMany($records);
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = fake()->domainName();
        $email = fake()->userName().'@'.$domain;

        return [
            'user_id' => User::factory(),
            'client_id' => $this->clientOfUser(),
            'email' => $email,
            'original_email' => $email,
            'name' => fake()->name(),
            'domain' => $domain,
            'mail_from_subdomain' => 'mas.'.$domain,
            'region' => fake()->randomElement(['us-east-1', 'us-west-2', 'eu-west-1']),
            'identity_status' => IdentityStatus::Verified,
            'verified_at' => now(),
            'last_status_check_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'identity_status' => IdentityStatus::Pending,
            'verified_at' => null,
        ]);
    }

    public function verifying(): static
    {
        return $this->state(fn (): array => [
            'identity_status' => IdentityStatus::Verifying,
            'verified_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'identity_status' => IdentityStatus::Failed,
            'verified_at' => null,
        ]);
    }

    public function withLinkDomain(?string $domain = null, bool $verified = true): static
    {
        return $this->state(fn (): array => [
            'custom_link_domain' => $domain ?? 'mail.'.fake()->domainName(),
            'custom_link_domain_verified_at' => $verified ? now() : null,
            'cf_custom_hostname_id' => fake()->uuid(),
        ]);
    }

    public function withConfigurationSet(?string $name = null): static
    {
        return $this->state(fn (): array => [
            'configuration_set_name' => $name ?? 'mailautomation-identity-'.fake()->randomNumber(5).'-'.fake()->uuid(),
            'configuration_set_created_at' => now(),
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => true,
            'tenant_associated_at' => now(),
        ]);
    }
}
