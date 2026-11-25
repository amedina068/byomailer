<?php

namespace App\Actions;

use App\Models\Contact;
use App\Models\User;
use App\Services\ContactMembership;
use Illuminate\Support\Facades\DB;

class UpsertContact
{
    public function __construct(private ContactMembership $membership) {}

    /**
     * Create the contact with this email in the client of the user, or update
     * the contact that has it. The row lock makes parallel calls for one email
     * wait for each other. A blacklisted email is saved as unsubscribed.
     *
     * @param  array{
     *     name?: string|null,
     *     tags?: array<int, string|null>,
     *     remove_tags?: array<int, string>,
     *     extra_attributes?: array<string, mixed>,
     * }  $changes
     */
    public function handle(User $user, string $email, array $changes = []): Contact
    {
        return DB::transaction(function () use ($user, $email, $changes): Contact {
            $contact = Contact::query()
                ->where('client_id', $user->client_id)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($contact === null) {
                $contact = new Contact(['client_id' => $user->client_id, 'email' => $email]);
                $changes += ['extra_attributes' => []];
            }

            return $this->membership->persist($user, $contact, $changes);
        }, attempts: 5);
    }
}
