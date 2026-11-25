<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DocumentationGuideController extends Controller
{
    public function gettingStarted(): Response
    {
        return Inertia::render('documentation/guides/GettingStarted');
    }

    public function createAwsAccount(): Response
    {
        return Inertia::render('documentation/guides/CreateAwsAccount');
    }

    public function createIamUser(): Response
    {
        return Inertia::render('documentation/guides/CreateIamUser');
    }

    public function createAccessKeys(): Response
    {
        return Inertia::render('documentation/guides/CreateAccessKeys');
    }

    public function createPolicies(): Response
    {
        return Inertia::render('documentation/guides/CreatePolicies');
    }

    public function attachPolicies(): Response
    {
        return Inertia::render('documentation/guides/AttachPolicies');
    }

    public function requestProductionAccess(): Response
    {
        return Inertia::render('documentation/guides/RequestProductionAccess');
    }

    public function customTrackingDomain(): Response
    {
        return Inertia::render('documentation/guides/CustomTrackingDomain');
    }

    public function emailTemplates(): Response
    {
        return Inertia::render('documentation/guides/EmailTemplates');
    }

    public function mcpServer(): Response
    {
        return Inertia::render('documentation/guides/McpServer');
    }
}
