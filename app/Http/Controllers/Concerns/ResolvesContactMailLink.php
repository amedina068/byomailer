<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use App\Models\TestMail;

/**
 * Shared lookup and mutation for the contact-facing subscription endpoints:
 * unsubscribe, one-click unsubscribe, resubscribe and opt-in.
 *
 * These routes are unauthenticated and served on customer link domains, so the
 * lookup verifies that the contact and the mailable belong to the same client
 * before anything is mutated. A teammate can send to a contact that another
 * member of the client added. One implementation, so the ownership check
 * cannot drift between the two directions.
 */
trait ResolvesContactMailLink
{
    protected function resolveContactMailFromLink(string $uuid): ContactMail
    {
        $contactMail = ContactMail::withoutGlobalScopes()
            ->with([
                'contact',
                'mailable' => function ($query): void {
                    $query->morphWith([
                        Newsletter::class,
                        SequenceStep::class => ['sequence'],
                    ]);
                },
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        abort_if(! $contactMail->contact, 404);

        if ($contactMail->mailable_type === TestMail::class) {
            return $contactMail;
        }

        $mailableClientId = match ($contactMail->mailable_type) {
            Newsletter::class, MailList::class => $contactMail->mailable?->client_id,
            SequenceStep::class => $contactMail->mailable?->sequence?->client_id,
            default => null,
        };

        abort_unless($mailableClientId !== null && $mailableClientId === $contactMail->contact->client_id, 404);

        return $contactMail;
    }

    /**
     * Resolve a double opt-in link to its contact and list. The list is read
     * through the membership of the contact, so the pivot is loaded and a
     * contact that left the list gets a 404. The contact and the list must
     * belong to the same client.
     */
    protected function resolveOptInMailFromLink(string $uuid): ContactMail
    {
        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('uuid', $uuid)
            ->where('mailable_type', MailList::class)
            ->firstOrFail();

        $contact = Contact::withoutGlobalScopes()->find($contactMail->contact_id);

        $list = $contact?->lists()
            ->withoutGlobalScopes()
            ->whereKey($contactMail->mailable_id)
            ->first();

        abort_if($list === null || $contact->client_id !== $list->client_id, 404);

        return $contactMail
            ->setRelation('contact', $contact)
            ->setRelation('mailable', $list);
    }

    protected function isTestMail(ContactMail $contactMail): bool
    {
        return $contactMail->mailable_type === TestMail::class;
    }

    /**
     * Unsubscribe the contact. Idempotent: repeating it is a no-op, which is
     * what RFC 8058 requires of the one-click endpoint.
     */
    protected function applyUnsubscribe(ContactMail $contactMail): void
    {
        if (! $contactMail->unsubscribed_at) {
            $contactMail->forceFill(['unsubscribed_at' => now()])->save();
        }

        $contact = $contactMail->contact;

        if ($contact instanceof Contact && ! $contact->unsubscribed_by_contact) {
            $contact->forceFill([
                'unsubscribed_at' => now(),
                'unsubscribed_by_contact' => true,
            ])->save();
        }
    }

    /**
     * Put the contact back on the list. Idempotent, like its counterpart.
     */
    protected function applyResubscribe(ContactMail $contactMail): void
    {
        $contactMail->forceFill(['unsubscribed_at' => null])->save();

        $contact = $contactMail->contact;

        if ($contact instanceof Contact) {
            $contact->forceFill([
                'unsubscribed_at' => null,
                'unsubscribed_by_contact' => false,
            ])->save();
        }
    }
}
