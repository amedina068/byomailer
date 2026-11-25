<?php

namespace App\Http\Controllers;

use App\Support\StaticDocs;
use Illuminate\Http\Response;

class DocumentationMarkdownController extends Controller
{
    public function __invoke(): Response
    {
        $markdownPath = public_path('docs/api-documentation.md');

        abort_unless(is_file($markdownPath), 404);

        return response(StaticDocs::withAppUrl((string) file_get_contents($markdownPath)), 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="api-documentation.md"',
        ]);
    }
}
