<?php

namespace App\Mcp\Tools;

use App\Actions\CreateTopic;
use App\Concerns\TopicValidationRules;
use App\Mcp\Concerns\FormatsMailLists;
use App\Models\MailList;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a topic in a mailing list. A topic is a subject inside one list. A contact can unsubscribe from one topic on the preference page and stay on the list. A topic name is unique in its list. After the create, each contact that counts on the list is subscribed to the topic in the background. Find list ids with list-lists. Returns the new topic.')]
class CreateTopicTool extends Tool
{
    use FormatsMailLists;
    use TopicValidationRules;

    protected string $name = 'create-topic';

    public function handle(Request $request, CreateTopic $createTopic): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'list_id' => ['required', 'integer'],
        ]);

        $list = MailList::query()->find($validated['list_id']);

        if ($list === null) {
            return Response::error("No list found with id {$validated['list_id']}.");
        }

        $data = $request->validate($this->topicRules($user->client_id, $list->id));

        $topic = $createTopic->handle($user, $list, $data);

        return Response::structured([
            ...$this->formatTopic($topic),
            'message' => "Topic #{$topic->id} created in list #{$list->id}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()
                ->description('Id of the list. Find list ids with list-lists.')
                ->required(),
            'name' => $schema->string()
                ->description('Name of the topic. It must be unique in the list.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional description that contacts see on the preference page. Maximum 1000 characters.'),
        ];
    }
}
