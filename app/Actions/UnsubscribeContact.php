<?php

namespace App\Actions;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnsubscribeContact
{
    /**
     * Unsubscribe the contact for an admin. A second call keeps the first
     * date, and `unsubscribed_by_contact` stays. The row lock makes a
     * parallel call wait, so `ContactObserver` fires `ContactUnsubscribed`
     * and cancels the pending mails one time.
     */
    public function handle(User $user, Contact $contact): Contact
    {
        return DB::transaction(function () use ($user, $contact): Contact {
            $current = Contact::query()
                ->where('client_id', $user->client_id)
                ->whereKey($contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->unsubscribed_at === null) {
                $current->forceFill(['unsubscribed_at' => now()])->save();
            }

            return $current;
        });
    }
}
