<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactListsSynced implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array{attached?: array<int, int>, detached?: array<int, int>, updated?: array<int, int>}  $changes
     */
    public function __construct(
        public Contact $contact,
        public array $changes,
    ) {}
}
