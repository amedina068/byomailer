<?php

namespace App\Events;

use App\Models\Contact;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A contact clicked a named link in an email. Only a named link fires this
 * event, because an automation selects the link by its name. The SES event
 * job records the click in a transaction, so the listeners wait for the commit.
 */
class ContactClickedLink implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Contact $contact,
        public string $linkName,
    ) {}
}
