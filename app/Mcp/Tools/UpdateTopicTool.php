<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateTopic;
use App\Concerns\TopicValidationRules;
use App\Mcp\Concerns\FormatsMailLists;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Change the name or the description of a topic. Only the fields that you pass change. Pass description null to clear it. A topic name is unique in its list. The subscriptions of the contacts do not change. Find topic ids with list-lists. Returns the topic.')]
class UpdateTopicTool extends Tool
{
    use FormatsMailLists;
    use TopicValidationRules;

    protected string $name = 'update-topic';

    public function handle(Request $request, UpdateTopic $updateTopic): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'topic_id' => ['required', 'integer'],
        ]);

        $topic = Topic::query()->find($validated['topic_id']);

        if ($topic === null) {
            return Response::error("No topic found with id {$validated['topic_id']}.");
        }

        $data = Validator::validate(
            [
                'name' => $topic->name,
                'description' => $topic->description,
                ...$request->all(['name', 'description']),
            ],
            $this->topicRules($user->client_id, $topic->list_id, $topic->id),
        );

        $updateTopic->handle($topic, $data);

        return Response::structured([
            ...$this->formatTopic($topic),
            'message' => "Topic #{$topic->id} updated.",
        ]);
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
            'name' => $schema->string()
                ->description('New name of the topic. It must be unique in the list.'),
            'description' => $schema->string()
                ->description('New description that contacts see on the preference page. Maximum 1000 characters. Pass null to clear it.'),
        ];
    }
}
