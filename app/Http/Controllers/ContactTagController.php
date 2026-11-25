<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContactTagController extends Controller
{
    public function sync(Request $request, $contactId): RedirectResponse
    {
        $request->validate([
            'tag_ids' => ['required', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $contact = Contact::query()->findOrFail($contactId);

        $validTagIds = Tag::query()
            ->whereIn('id', $request->input('tag_ids'))
            ->pluck('id')
            ->all();

        $contact->syncTags($validTagIds);

        return back();
    }
}
