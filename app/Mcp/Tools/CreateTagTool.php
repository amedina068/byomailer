<?php

namespace App\Mcp\Tools;

use App\Actions\CreateTag;
use App\Concerns\TagValidationRules;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a tag for the authenticated account. Tags label contacts and drive newsletter/sequence audience filters. Tag names are unique per account — creating a duplicate name returns a validation error. Returns the new tag id and name.')]
class CreateTagTool extends Tool
{
    use TagValidationRules;

    public function handle(Request $request, CreateTag $createTag): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate($this->tagRules($user->client_id));

        $tag = $createTag->handle($user, $validated['name']);

        return Response::structured([
            'id' => $tag->id,
            'name' => $tag->name,
            'message' => "Tag #{$tag->id} created.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Name for the tag. Must be unique within the account.')
                ->required(),
        ];
    }
}
