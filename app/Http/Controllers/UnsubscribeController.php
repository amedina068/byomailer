<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactMailLink;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the unsubscribe confirmation page. This is a GET and therefore must
 * not change anything: link scanners and mail-client prefetchers follow every
 * URL in a message, and a mutating GET let them unsubscribe contacts who never
 * clicked. The contact confirms with a POST to ConfirmUnsubscribeController.
 */
class UnsubscribeController extends Controller
{
    use ResolvesContactMailLink;

    public function __invoke(string $contactMail): Response
    {
        $contactMailModel = $this->resolveContactMailFromLink($contactMail);
        $contactModel = $contactMailModel->contact;

        return Inertia::render('Unsubscribe', [
            'isTestMail' => $this->isTestMail($contactMailModel),
            'alreadyUnsubscribed' => (bool) $contactModel->unsubscribed_by_contact,
            'contact' => [
                'name' => $contactModel->name,
                'email' => $contactModel->email,
            ],
            'contactMailUuid' => $contactMailModel->uuid,
        ]);
    }
}
