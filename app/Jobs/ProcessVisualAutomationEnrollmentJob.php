<?php

namespace App\Jobs;

use App\Models\VisualAutomationEnrollment;
use App\Services\VisualAutomationEngine;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Queue\Queueable;

class ProcessVisualAutomationEnrollmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $enrollmentId) {}

    public function uniqueId(): string
    {
        return (string) $this->enrollmentId;
    }

    public function handle(VisualAutomationEngine $engine): void
    {
        $enrollment = VisualAutomationEnrollment::query()
            ->with([
                'visualAutomation' => fn (BelongsTo $query) => $query->withoutGlobalScopes(),
                'contact' => fn (BelongsTo $query) => $query->withoutGlobalScopes(),
            ])
            ->find($this->enrollmentId);

        if (! $enrollment?->visualAutomation) {
            return;
        }

        Tenant::run($enrollment->visualAutomation->client_id, fn () => $engine->processEnrollment($enrollment));
    }
}
