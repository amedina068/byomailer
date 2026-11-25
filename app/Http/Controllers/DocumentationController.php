<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class DocumentationController extends Controller
{
    /**
     * A permanent redirect, so search engines index the getting started guide in place of /documentation.
     */
    public function __invoke(): RedirectResponse
    {
        return redirect()->route('documentation.guides.getting-started', status: 301);
    }
}
