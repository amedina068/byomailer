<?php

namespace App\Mcp\Tools;

use App\Actions\DeleteTopic;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\FormatsMailLists;
use App\Models\Topic;
use App\Support\AudienceUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Delete a topic and the topic subscriptions of its contacts. The contacts stay on the list. The choices of the contacts that unsubscribed from the topic are deleted too, and the delete cannot be undone. This is two calls. The first call saves nothing and returns a preview with the subscribed and the unsubscribed contacts. Show the preview to the user. After the user confirms, call the tool again with the same arguments and confirm_live set to true. Never set confirm_live without that confirmation. When newsletters, sequences, segments or automations use the topic, the tool refuses the delete and names them. Find topic ids with list-lists.')]
class DeleteTopicTool extends Tool
{
    use ConfirmsLiveEdits;
    use FormatsMailLists;

    protected string $name = 'delete-topic';

    public function handle(Request $request, DeleteTopic $deleteTopic): Response|ResponseFactory
    {
        $validated = $request->validate([
            'topic_id' => ['required', 'integer'],
            ...$this->confirmLiveValidationRules(),
        ]);

        $topic = Topic::query()->find($validated['topic_id']);

        if ($topic === null) {
            return Response::error("No topic found with id {$validated['topic_id']}.");
        }

        $usage = AudienceUsage::ofTopic($topic);

        if (AudienceUsage::isInUse($usage)) {
            return Response::error("Topic #{$topic->id} was not deleted. These items use the topic: ".AudienceUsage::describe($usage).'. Remove the topic from them, then delete the topic.');
        }

        if (! $this->confirmsLive($validated)) {
            return $this->statusChangePreview($this->deletePreview($topic));
        }

        try {
            $deleteTopic->handle($topic);
        } catch (ValidationException $exception) {
            return Response::error("Topic #{$topic->id} was not deleted. ".implode(' ', $exception->validator->errors()->all()));
        }

        return Response::structured([
            ...$this->formatTopic($topic),
            'applied' => true,
            'message' => "Topic #{$topic->id} deleted.",
        ]);
    }

    /**
     * Describe the delete without saving it.
     *
     * @return array<string, mixed>
     */
    private function deletePreview(Topic $topic): array
    {
        $subscribed = $topic->contacts()->wherePivotNull('unsubscribed_at')->count();
        $unsubscribed = $topic->contacts()->wherePivotNotNull('unsubscribed_at')->count();

        return [
            ...$this->formatTopic($topic),
            'change' => 'delete',
            'subscribed_contacts' => $subscribed,
            'unsubscribed_contacts' => $unsubscribed,
            'effects' => [
                "{$subscribed} contacts lose their subscription to the topic. They stay on the list.",
                "{$unsubscribed} contacts unsubscribed from the topic. Their choice is deleted with the topic.",
                'The topic is removed from the preference page. The delete cannot be undone.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topic_id' => $schema->integer()
                ->description('Id of the topic. Find topic ids with list-lists.')
                ->required(),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
