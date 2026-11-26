<?php

namespace App\Mcp\Tools;

use App\Actions\RenameTag;
use App\Concerns\TagValidationRules;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Rename a tag. Tag names are unique per account, so a name that another tag has returns a validation error. Tag filters select tags by name. If a saved filter of a newsletter that can still send, a sequence, a sequence step or a segment uses the tag, the rename is refused. Find tag ids with list-tags.')]
class RenameTagTool extends Tool
{
    use TagValidationRules;

    protected string $name = 'rename-tag';

    public function handle(Request $request, RenameTag $renameTag): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['tag_id' => ['required', 'integer']]);

        $tag = Tag::query()->find($validated['tag_id']);

        if ($tag === null) {
            return Response::error("No tag found with id {$validated['tag_id']}.");
        }

        $name = $request->validate($this->tagRules($user->client_id, $tag))['name'];
        $oldName = $tag->name;

        $renameTag->handle($tag, $name);

        return Response::structured([
            'id' => $tag->id,
            'name' => $tag->name,
            'previous_name' => $oldName,
            'message' => "Tag #{$tag->id} renamed.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'tag_id' => $schema->integer()
                ->description('Id of the tag. Use list-tags to find tag ids.')
                ->required(),
            'name' => $schema->string()
                ->description('The new name. It must be unique in the account.')
                ->required(),
        ];
    }
}
