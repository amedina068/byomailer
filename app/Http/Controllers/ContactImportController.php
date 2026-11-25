<?php

namespace App\Http\Controllers;

use App\Http\Resources\MailListResource;
use App\Models\MailList;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactImportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $lists = MailList::query()
            ->orderBy('name')
            ->get();

        $selectedListId = $request->integer('list_id');

        return Inertia::render('contacts/Import', [
            'lists' => MailListResource::collection($lists)->resolve($request),
            'selectedListId' => $selectedListId > 0 ? $selectedListId : null,
        ]);
    }
}
