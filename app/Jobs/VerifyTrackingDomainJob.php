<?php

namespace App\Jobs;

use App\Models\EmailIdentity;
use App\Services\TrackingDomainVerifier;
use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class VerifyTrackingDomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $emailIdentityId)
    {
        $this->onQueue('tracking-domains');
    }

    public function handle(TrackingDomainVerifier $verifier): void
    {
        $identity = EmailIdentity::withoutGlobalScopes()->find($this->emailIdentityId);

        if (! $identity || ! $identity->custom_tracking_domain || $identity->custom_tracking_domain_verified_at) {
            return;
        }

        Tenant::run($identity->client_id, fn () => $verifier->verify($identity));
    }
}
