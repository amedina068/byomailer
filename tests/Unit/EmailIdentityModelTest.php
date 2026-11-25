<?php

namespace Tests\Unit;

use App\Enums\DkimStatus;
use App\Enums\DnsRecordPurpose;
use App\Enums\IdentityStatus;
use App\Enums\MailFromStatus;
use App\Models\EmailIdentity;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailIdentityModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_verified_returns_true_when_identity_status_is_verified(): void
    {
        $identity = EmailIdentity::factory()->create([
            'identity_status' => IdentityStatus::Verified,
        ]);

        $this->assertTrue($identity->isVerified());
    }

    public function test_is_verified_returns_false_when_identity_status_is_pending(): void
    {
        $identity = EmailIdentity::factory()->pending()->create();

        $this->assertFalse($identity->isVerified());
    }

    public function test_get_dns_records_returns_all_records(): void
    {
        $identity = EmailIdentity::factory()->create(['domain' => 'example.com', 'mail_from_subdomain' => 'mas.example.com']);
        $identity->dnsRecords()->delete();

        $records = [
            ['type' => 'CNAME', 'name' => 'token1._domainkey.example.com', 'value' => 'token1.dkim.amazonses.com'],
            ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10],
        ];

        $identity->dnsRecords()->createMany($records);

        $this->assertSame($records, $identity->fresh()->getDnsRecords());
    }

    public function test_get_dns_records_returns_empty_array_when_missing_records(): void
    {
        $identity = EmailIdentity::factory()->create();
        $identity->dnsRecords()->delete();

        $this->assertSame([], $identity->fresh()->getDnsRecords());
    }

    public function test_dkim_status_derived_from_dns_records(): void
    {
        $identity = EmailIdentity::factory()->pending()->create(['domain' => 'example.com']);
        $identity->dnsRecords()->delete();

        $identity->dnsRecords()->createMany([
            ['type' => 'CNAME', 'name' => 'token1._domainkey.example.com', 'value' => 'token1.dkim.amazonses.com', 'purpose' => 'dkim', 'verified_at' => now()],
            ['type' => 'CNAME', 'name' => 'token2._domainkey.example.com', 'value' => 'token2.dkim.amazonses.com', 'purpose' => 'dkim', 'verified_at' => null],
        ]);

        $this->assertSame(DkimStatus::Pending, $identity->fresh()->dkim_status);

        $identity->dnsRecords()->whereNull('verified_at')->update(['verified_at' => now()]);

        $this->assertSame(DkimStatus::Success, $identity->fresh()->dkim_status);
    }

    public function test_mail_from_status_derived_from_dns_records(): void
    {
        $identity = EmailIdentity::factory()->pending()->create([
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
        ]);
        $identity->dnsRecords()->delete();

        $identity->dnsRecords()->createMany([
            ['type' => 'MX', 'name' => 'mas.example.com', 'value' => 'feedback-smtp.us-east-1.amazonses.com', 'priority' => 10, 'verified_at' => now()],
            ['type' => 'TXT', 'name' => 'mas.example.com', 'value' => 'v=spf1 include:amazonses.com ~all', 'verified_at' => null],
        ]);

        $this->assertSame(MailFromStatus::Pending, $identity->fresh()->mail_from_status);

        $identity->dnsRecords()->update(['verified_at' => now()]);

        $this->assertSame(MailFromStatus::Success, $identity->fresh()->mail_from_status);
    }

    public function test_casts_identity_status_to_enum(): void
    {
        $identity = EmailIdentity::factory()->create([
            'identity_status' => IdentityStatus::Pending,
        ]);

        $this->assertInstanceOf(IdentityStatus::class, $identity->identity_status);
        $this->assertSame(IdentityStatus::Pending, $identity->identity_status);
    }

    public function test_casts_dns_record_purpose_to_enum(): void
    {
        $identity = EmailIdentity::factory()->create(['domain' => 'example.com']);
        $identity->dnsRecords()->delete();

        $record = $identity->dnsRecords()->create([
            'type' => 'TXT',
            'name' => '_dmarc.example.com',
            'value' => 'v=DMARC1; p=none;',
            'purpose' => DnsRecordPurpose::Dmarc,
        ]);

        $this->assertDatabaseHas('email_identity_dns_records', ['id' => $record->id, 'purpose' => 'dmarc']);
        $this->assertSame(DnsRecordPurpose::Dmarc, $record->fresh()->purpose);
        $this->assertSame('dmarc', $identity->fresh()->getDnsRecords()[0]['purpose']);
    }

    public function test_casts_last_status_check_at_to_datetime(): void
    {
        $identity = EmailIdentity::factory()->create([
            'last_status_check_at' => '2026-01-27 12:00:00',
        ]);

        $this->assertInstanceOf(DateTimeInterface::class, $identity->last_status_check_at);
    }

    public function test_casts_custom_link_domain_verified_at_to_datetime(): void
    {
        $identity = EmailIdentity::factory()->create([
            'custom_link_domain_verified_at' => '2026-05-16 12:00:00',
        ]);

        $this->assertInstanceOf(DateTimeInterface::class, $identity->custom_link_domain_verified_at);
    }

    public function test_has_verified_link_domain_returns_true_when_domain_set_and_verified(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com')->create();

        $this->assertTrue($identity->hasVerifiedLinkDomain());
    }

    public function test_has_verified_link_domain_returns_false_when_domain_unverified(): void
    {
        $identity = EmailIdentity::factory()->withLinkDomain('mail.example.com', verified: false)->create();

        $this->assertFalse($identity->hasVerifiedLinkDomain());
    }

    public function test_has_verified_link_domain_returns_false_when_no_domain(): void
    {
        $identity = EmailIdentity::factory()->create([
            'custom_link_domain' => null,
            'custom_link_domain_verified_at' => null,
        ]);

        $this->assertFalse($identity->hasVerifiedLinkDomain());
    }
}
