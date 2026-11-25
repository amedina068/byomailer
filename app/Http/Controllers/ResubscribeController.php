<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactMailLink;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the resubscribe confirmation page. Like the unsubscribe page, this
 * is a GET and must not change anything: a GET carries no CSRF protection, so
 * a leaked link in a prefetcher or an `<img src>` would put a contact who
 * opted out back on the list. The contact confirms with a POST to
 * ConfirmResubscribeController.
 */
class ResubscribeController extends Controller
{
    use ResolvesContactMailLink;

    public function __invoke(string $contactMail): Response
    {
        $contactMailModel = $this->resolveContactMailFromLink($contactMail);
        $contactModel = $contactMailModel->contact;

        return Inertia::render('Resubscribe', [
            'isTestMail' => $this->isTestMail($contactMailModel),
            'alreadySubscribed' => ! $contactModel->unsubscribed_at,
            'contact' => [
                'name' => $contactModel->name,
                'email' => $contactModel->email,
            ],
            'contactMailUuid' => $contactMailModel->uuid,
        ]);
    }
}
