<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactMailLink;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the confirmation POST from the unsubscribe page. CSRF protected,
 * unlike the one-click endpoint, because it is a normal browser form.
 */
class ConfirmUnsubscribeController extends Controller
{
    use ResolvesContactMailLink;

    public function __invoke(string $contactMail): Response
    {
        $contactMailModel = $this->resolveContactMailFromLink($contactMail);
        $contactModel = $contactMailModel->contact;

        $isTestMail = $this->isTestMail($contactMailModel);

        if (! $isTestMail) {
            $this->applyUnsubscribe($contactMailModel);
        }

        return Inertia::render('Unsubscribed', [
            'isTestMail' => $isTestMail,
            'contact' => [
                'name' => $contactModel->name,
                'email' => $contactModel->email,
            ],
            'contactMailUuid' => $contactMailModel->uuid,
        ]);
    }
}
