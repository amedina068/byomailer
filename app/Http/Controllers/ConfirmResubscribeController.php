<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesContactMailLink;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Handles the resubscribe POST from the unsubscribe pages and from the
 * resubscribe confirmation page. CSRF protected: it is always a browser form,
 * never a mailbox provider.
 */
class ConfirmResubscribeController extends Controller
{
    use ResolvesContactMailLink;

    public function __invoke(string $contactMail): Response
    {
        $contactMailModel = $this->resolveContactMailFromLink($contactMail);

        $isTestMail = $this->isTestMail($contactMailModel);

        if (! $isTestMail) {
            $this->applyResubscribe($contactMailModel);
        }

        return Inertia::render('Resubscribed', [
            'isTestMail' => $isTestMail,
        ]);
    }
}
