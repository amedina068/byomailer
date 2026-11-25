<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactMailLink;
use App\Services\ContactMembership;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the POST from the double opt-in confirmation page. CSRF protected:
 * only a person on the page sends it, never a mail scanner.
 */
class ConfirmOptInController extends Controller
{
    use ResolvesContactMailLink;

    public function __invoke(string $contactMail, ContactMembership $membership): Response
    {
        $contactMailModel = $this->resolveOptInMailFromLink($contactMail);
        $contact = $contactMailModel->contact;
        $list = $contactMailModel->mailable;

        $membership->confirmOptIn($contact, $list);

        return Inertia::render('OptedIn', [
            'contact' => [
                'name' => $contact->name,
                'email' => $contact->email,
            ],
            'list' => [
                'name' => $list->name,
            ],
        ]);
    }
}
