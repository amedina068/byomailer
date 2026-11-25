<?php

namespace App\Mcp\Tools;

use App\Actions\SendNewsletterTestMail;
use App\Exceptions\TestMailException;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Send a one-off test copy of a newsletter to the authenticated user\'s own email address so they can preview it. The newsletter must already have a sender identity attached. Does not send to the newsletter audience. Find the newsletter id with list-newsletters-tool or create-newsletter-tool.')]
class SendNewsletterTestMailTool extends Tool
{
    public function __construct(private readonly SendNewsletterTestMail $sender) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'newsletter_id' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $newsletter = Newsletter::query()->find($validated['newsletter_id']);

        if (! $newsletter) {
            return Response::error("No newsletter found with id {$validated['newsletter_id']}.");
        }

        try {
            $this->sender->handle($user, $newsletter);
        } catch (TestMailException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'message' => 'Test email sent to '.$user->email,
            'sent_to' => $user->email,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'newsletter_id' => $schema->integer()
                ->description('The id of the newsletter to send a test copy of.')
                ->required(),
        ];
    }
}
