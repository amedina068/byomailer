<?php

namespace App\Mcp\Tools;

use App\Actions\CreateMailList;
use App\Concerns\MailListValidationRules;
use App\Mcp\Concerns\FormatsMailLists;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a mailing list in the account. A list name is unique in the account. A name that exists returns a validation error. A new list has no opt-in and no topics. To turn on opt-in, use update-list. To add topics, use create-topic. Returns the new list.')]
class CreateListTool extends Tool
{
    use FormatsMailLists;
    use MailListValidationRules;

    protected string $name = 'create-list';

    public function handle(Request $request, CreateMailList $createMailList): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate($this->mailListRules($user->client_id));

        $list = $createMailList->handle($user, $validated['name']);

        return Response::structured([
            ...$this->formatMailList($list),
            'message' => "List #{$list->id} created.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Name of the list. It must be unique in the account.')
                ->required(),
        ];
    }
}
