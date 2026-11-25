<?php

namespace Tests\Feature;

use App\Enums\DkimStatus;
use App\Enums\DnsRecordPurpose;
use App\Enums\MailFromStatus;
use App\Models\EmailIdentity;
use App\Services\EmailIdentityStatusSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailIdentityStatusSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_aws_success_marks_the_dkim_and_mail_from_records_verified(): void
    {
        $identity = $this->identity();

        app(EmailIdentityStatusSyncService::class)->syncDnsVerification($identity, 'SUCCESS', 'success');

        $identity = $identity->fresh();
        $this->assertSame(DkimStatus::Success, $identity->dkim_status);
        $this->assertSame(MailFromStatus::Success, $identity->mail_from_status);
    }

    public function test_an_unknown_aws_status_leaves_the_records_unverified(): void
    {
        $identity = $this->identity();
        $identity->dnsRecords()->update(['verified_at' => now()]);

        app(EmailIdentityStatusSyncService::class)->syncDnsVerification($identity, 'A_NEW_AWS_STATUS', null);

        $identity = $identity->fresh();
        $this->assertSame(DkimStatus::Pending, $identity->dkim_status);
        $this->assertSame(MailFromStatus::Pending, $identity->mail_from_status);
        $this->assertNotNull($identity->dnsRecords->firstWhere('purpose', DnsRecordPurpose::Dmarc)->verified_at);
    }

    private function identity(): EmailIdentity
    {
        return EmailIdentity::factory()->pending()->create([
            'domain' => 'example.com',
            'mail_from_subdomain' => 'mas.example.com',
            'region' => 'us-east-1',
        ]);
    }
}
