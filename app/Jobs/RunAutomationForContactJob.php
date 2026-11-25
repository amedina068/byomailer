<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Models\Contact;
use App\Services\AutomationRunner;
use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAutomationForContactJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Automation $automation,
        public Contact $contact,
    ) {
        $this->onQueue('automations');
    }

    public function handle(AutomationRunner $automationRunner): void
    {
        Tenant::run($this->contact->client_id, fn () => $automationRunner->runForContact($this->automation, $this->contact));
    }
}
