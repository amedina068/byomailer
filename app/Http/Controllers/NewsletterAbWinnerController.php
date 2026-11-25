<?php

namespace App\Http\Controllers;

use App\Actions\PickAbTestWinner;
use App\Enums\AbVariant;
use App\Http\Requests\NewsletterAbWinnerRequest;
use App\Models\Newsletter;
use Illuminate\Http\RedirectResponse;

class NewsletterAbWinnerController extends Controller
{
    public function __invoke(NewsletterAbWinnerRequest $request, Newsletter $newsletter, PickAbTestWinner $pickAbTestWinner): RedirectResponse
    {
        $pickAbTestWinner->handle($newsletter, AbVariant::from($request->validated('winner_variant')));

        return back();
    }
}
