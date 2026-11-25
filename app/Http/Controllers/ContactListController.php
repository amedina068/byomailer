<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\MailList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactListController extends Controller
{
    public function sync(Request $request, $contactId): RedirectResponse
    {
        $request->validate([
            'list_ids' => ['required', 'array'],
            'list_ids.*' => ['integer'],
        ]);

        $contact = Contact::query()->findOrFail($contactId);

        $validListIds = MailList::query()
            ->whereIn('id', $request->input('list_ids'))
            ->pluck('id')
            ->all();

        $contact->syncLists($validListIds);

        return back();
    }
}
