<?php

namespace App\Actions;

use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\User;
use App\Services\ContactMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscribeContact
{
    public function __construct(private ContactMembership $membership) {}

    /**
     * Clear an unsubscribe that an admin made. An unsubscribe that the
     * contact made stays. A complaint, a block of the server operator and a
     * global suppression stay. The client can remove its own bounce
     * suppression, but only when the caller confirms it.
     *
     * @throws ValidationException
     */
    public function handle(User $user, Contact $contact, bool $removeSuppression = false): Contact
    {
        if ($contact->unsubscribed_at !== null && $contact->unsubscribed_by_contact) {
            throw $this->optedOut();
        }

        $suppression = $contact->suppression();

        if ($suppression !== null && ! $suppression->isRemovable()) {
            throw ValidationException::withMessages([
                'contact' => 'The address is on the suppression list after a complaint, or the server operator blocked it. BYOMailer cannot mail it again.',
            ]);
        }

        if ($suppression !== null && ! $removeSuppression) {
            throw ValidationException::withMessages([
                'remove_suppression' => 'Confirm that you remove the address from the suppression list.',
            ]);
        }

        DB::transaction(function () use ($user, $contact, $suppression): void {
            if ($suppression !== null) {
                $this->removeClientSuppressions($contact);
            }

            if (! $this->membership->resubscribe($user, $contact)) {
                throw $this->optedOut();
            }
        });

        return $contact;
    }

    /**
     * The first suppression row is removable, so each row of the address is
     * a bounce row of the client.
     */
    private function removeClientSuppressions(Contact $contact): void
    {
        BlacklistedEmail::withoutGlobalScopes()
            ->where('client_id', $contact->client_id)
            ->where('email', Str::lower($contact->email))
            ->get()
            ->each(fn (BlacklistedEmail $row) => $row->delete());
    }

    private function optedOut(): ValidationException
    {
        return ValidationException::withMessages([
            'contact' => 'This contact unsubscribed from your emails. Only the contact can subscribe again.',
        ]);
    }
}
