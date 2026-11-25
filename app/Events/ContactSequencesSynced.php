<?php

namespace App\Events;

use App\Models\Automation;
use App\Models\Contact;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ContactSequencesSynced
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{attached?: array<int, int>, detached?: array<int, int>, updated?: array<int, int>}  $changes
     * @param  Collection<int, Automation>  $automations
     */
    public function __construct(
        public Contact $contact,
        public array $changes,
        public Collection $automations,
    ) {}
}
