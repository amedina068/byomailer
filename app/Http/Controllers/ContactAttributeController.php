<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\ContactMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactAttributeController extends Controller
{
    public function __construct(private ContactMembership $membership) {}

    public function store(Request $request, $contactId): RedirectResponse
    {
        $validated = $request->validate([
            'attribute_name' => ['required', 'string', 'max:255'],
            'attribute_value' => ['required', 'string', 'max:255'],
        ]);

        $contact = Contact::query()->findOrFail($contactId);

        $this->membership->persist($request->user(), $contact, [
            'extra_attributes' => [$validated['attribute_name'] => $validated['attribute_value']],
        ]);

        return back();
    }

    public function update(Request $request, $contactId, string $attributeName): RedirectResponse
    {
        $validated = $request->validate([
            'attribute_value' => ['required', 'string', 'max:255'],
        ]);

        $contact = $this->contactWithAttribute($contactId, $attributeName);

        $this->membership->persist($request->user(), $contact, [
            'extra_attributes' => [$attributeName => $validated['attribute_value']],
        ]);

        return back();
    }

    public function destroy(Request $request, $contactId, string $attributeName): RedirectResponse
    {
        $contact = $this->contactWithAttribute($contactId, $attributeName);

        $this->membership->persist($request->user(), $contact, [
            'remove_attributes' => [$attributeName],
        ]);

        return back();
    }

    private function contactWithAttribute(int|string $contactId, string $attributeName): Contact
    {
        $contact = Contact::query()->findOrFail($contactId);

        abort_unless(array_key_exists($attributeName, $contact->extra_attributes ?? []), 404);

        return $contact;
    }
}
