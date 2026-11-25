<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DocumentationApiController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('documentation/api/Index');
    }

    public function contacts(): Response
    {
        return Inertia::render('documentation/api/Contacts');
    }

    public function tags(): Response
    {
        return Inertia::render('documentation/api/Tags');
    }

    public function lists(): Response
    {
        return Inertia::render('documentation/api/Lists');
    }

    public function blocks(): Response
    {
        return Inertia::render('documentation/api/Blocks');
    }

    public function newsletters(): Response
    {
        return Inertia::render('documentation/api/Newsletters');
    }

    public function sequences(): Response
    {
        return Inertia::render('documentation/api/Sequences');
    }

    public function linkClicks(): Response
    {
        return Inertia::render('documentation/api/LinkClicks');
    }

    public function projects(): Response
    {
        return Inertia::render('documentation/api/Projects');
    }

    public function filters(): Response
    {
        return Inertia::render('documentation/api/Filters');
    }

    public function emailIdentities(): Response
    {
        return Inertia::render('documentation/api/EmailIdentities');
    }

    public function emailTemplates(): Response
    {
        return Inertia::render('documentation/api/EmailTemplates');
    }

    public function topics(): Response
    {
        return Inertia::render('documentation/api/Topics');
    }

    public function emailLogs(): Response
    {
        return Inertia::render('documentation/api/EmailLogs');
    }

    public function webhooks(): Response
    {
        return Inertia::render('documentation/api/Webhooks');
    }
}
