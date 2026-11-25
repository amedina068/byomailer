<?php

namespace App\Mcp\Tools;

use App\Actions\SendSequenceStepTestMail;
use App\Exceptions\TestMailException;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Send a one-off test copy of a single sequence step to the authenticated user\'s own email address so they can preview it. The step must already have a sender identity attached. Does not send to the sequence audience and does not start or advance the sequence for anyone. Find the sequence and step ids with list-sequences-tool and sequence-content-tool.')]
class SendSequenceStepTestMailTool extends Tool
{
    public function __construct(private readonly SendSequenceStepTestMail $sender) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'sequence_id' => ['required', 'integer'],
            'step_id' => ['required', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $sequence = Sequence::query()->find($validated['sequence_id']);

        if (! $sequence) {
            return Response::error("No sequence found with id {$validated['sequence_id']}.");
        }

        $step = $sequence->steps()
            ->with(['message.emailIdentity'])
            ->find($validated['step_id']);

        if (! $step) {
            return Response::error("No step with id {$validated['step_id']} found on sequence {$validated['sequence_id']}.");
        }

        $step->setRelation('sequence', $sequence);

        try {
            $this->sender->handle($user, $step);
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
            'sequence_id' => $schema->integer()
                ->description('The id of the sequence the step belongs to.')
                ->required(),
            'step_id' => $schema->integer()
                ->description('The id of the sequence step to send a test copy of.')
                ->required(),
        ];
    }
}
