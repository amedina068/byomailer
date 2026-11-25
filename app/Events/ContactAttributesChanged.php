<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactAttributesChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, array{old: ?string, new: ?string}>  $changes
     */
    public function __construct(
        public Contact $contact,
        public array $changes,
    ) {}
}
