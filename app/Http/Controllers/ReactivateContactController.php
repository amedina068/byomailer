<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\SunsetPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Clears the sunset mark on a contact, so BYOMailer mails the contact again.
 * The subscription state does not change.
 */
class ReactivateContactController extends Controller
{
    public function __invoke(Request $request, int $contactId): RedirectResponse
    {
        $contact = Contact::query()->findOrFail($contactId);

        $contact->update(['sunset_at' => null]);

        $days = $request->user()->client->sunset_inactive_days;

        if ($days !== null) {
            Cache::forget(SunsetPolicy::previewCacheKey($contact->client_id, $days));
        }

        return back();
    }
}
