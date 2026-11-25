<?php

namespace App\Observers;

use App\Events\ContactResubscribed;
use App\Events\ContactUnsubscribed;
use App\Models\Contact;

class ContactObserver
{
    public function saved(Contact $contact): void
    {
        $contact->syncAttributeDefinitions();
    }

    public function updated(Contact $contact): void
    {
        if (! $contact->wasChanged('unsubscribed_at')) {
            return;
        }

        if ($contact->unsubscribed_at) {
            $contact->cancelPendingMailings();
            $contact->sequences()->detach();

            if (! $contact->webhooksSuppressed) {
                ContactUnsubscribed::dispatch($contact);
            }

            return;
        }

        if ($contact->getOriginal('unsubscribed_at') !== null) {
            ContactResubscribed::dispatch($contact);
        }
    }
}
