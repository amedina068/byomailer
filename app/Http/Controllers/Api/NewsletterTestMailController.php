<?php

namespace App\Http\Controllers\Api;

use App\Actions\SendNewsletterTestMail;
use App\Exceptions\TestMailException;
use App\Http\Controllers\Controller;
use App\Models\Newsletter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[Group('Newsletters', 'A newsletter is one email to an audience of contacts. Create and edit drafts, schedule a send, pick the winner of an A/B test, and send a test email to yourself.', weight: 5)]
class NewsletterTestMailController extends Controller
{
    public function __construct(private readonly SendNewsletterTestMail $sender) {}

    /**
     * Send a test email.
     *
     * Sends the newsletter to the email address of the token user, through the SES
     * account of your client. The newsletter needs a verified email identity. The
     * limit is 5 requests a minute for each user.
     */
    #[Response(422, description: 'The newsletter has no sender (`email_identity_id`), or SES did not accept the email (`send`).', type: 'array{message: string, errors: array{email_identity_id?: list<string>, send?: list<string>}}')]
    public function __invoke(Request $request, int $newsletterId): JsonResponse
    {
        $user = $request->user();

        $newsletter = Newsletter::query()->findOrFail($newsletterId);

        try {
            $this->sender->handle($user, $newsletter);
        } catch (TestMailException $e) {
            $field = $e->reason === TestMailException::REASON_NO_IDENTITY
                ? 'email_identity_id'
                : 'send';

            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }

        return response()->json([
            'message' => 'Test email sent to '.$user->email,
            'sent_to' => $user->email,
        ]);
    }
}
