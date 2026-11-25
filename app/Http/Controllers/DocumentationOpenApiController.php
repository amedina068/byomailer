<?php

namespace App\Http\Controllers;

use App\Support\StaticDocs;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class DocumentationOpenApiController extends Controller
{
    public function __invoke(): Response
    {
        $path = public_path('docs/openapi.json');

        if (! file_exists($path)) {
            Artisan::call('scramble:export');
        }

        return response(StaticDocs::withAppUrl((string) file_get_contents($path)), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="byomailer-openapi-schema.json"',
        ]);
    }
}
