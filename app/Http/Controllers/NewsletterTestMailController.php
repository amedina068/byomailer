<?php

namespace App\Http\Controllers;

use App\Actions\SendNewsletterTestMail;
use App\Exceptions\TestMailException;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewsletterTestMailController extends Controller
{
    public function __construct(private SendNewsletterTestMail $sender) {}

    public function __invoke(Request $request, int $newsletterId): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'email_identity_id' => ['nullable', 'integer'],
        ]);

        $newsletter = Newsletter::query()->findOrFail($newsletterId);

        $identity = null;

        if (! empty($validated['email_identity_id'])) {
            $identity = EmailIdentity::query()->find($validated['email_identity_id']);

            if ($identity === null) {
                return back()->with('error', 'The selected sender is not available.');
            }
        }

        try {
            $this->sender->handle($user, $newsletter, $identity);
        } catch (TestMailException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Test email sent to '.$user->email);
    }
}
