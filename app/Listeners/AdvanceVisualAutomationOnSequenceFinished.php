<?php

namespace App\Listeners;

use App\Events\ContactSequenceFinished;
use App\Services\VisualAutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;

class AdvanceVisualAutomationOnSequenceFinished implements ShouldQueue
{
    public function __construct(private VisualAutomationEngine $engine) {}

    public function viaQueue(): string
    {
        return 'automations';
    }

    public function handle(ContactSequenceFinished $event): void
    {
        $this->engine->advanceWaitingSequenceSteps($event->contact, $event->sequence);
    }
}
