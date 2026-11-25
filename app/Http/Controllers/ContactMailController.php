<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContactMailResource;
use App\Models\ContactMail;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactMailController extends Controller
{
    public function show(Request $request, $contactMailId): Response
    {
        $contactMail = ContactMail::query()
            ->with([
                'mailable' => function ($query): void {
                    $query->morphWith([
                        Newsletter::class => ['message'],
                        SequenceStep::class => ['sequence', 'message'],
                    ]);
                },
            ])
            ->findOrFail($contactMailId);

        return Inertia::render('contact-mails/Show', [
            'mail' => ContactMailResource::make($contactMail)->resolve($request),
        ]);
    }
}
