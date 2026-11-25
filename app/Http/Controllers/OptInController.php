<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactMailLink;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the double opt-in confirmation page. This GET must not change
 * anything: mail scanners and link prefetchers open each link in an email,
 * so a confirmation on GET confirms addresses that no person clicked. The
 * contact confirms with a POST to ConfirmOptInController.
 */
class OptInController extends Controller
{
    use ResolvesContactMailLink;

    public function __invoke(string $contactMail): Response
    {
        $contactMailModel = $this->resolveOptInMailFromLink($contactMail);
        $contact = $contactMailModel->contact;
        $list = $contactMailModel->mailable;

        return Inertia::render('OptIn', [
            'alreadyConfirmed' => $list->pivot->opted_in_at !== null,
            'contact' => [
                'name' => $contact->name,
                'email' => $contact->email,
            ],
            'list' => [
                'name' => $list->name,
            ],
            'contactMailUuid' => $contactMailModel->uuid,
        ]);
    }
}
