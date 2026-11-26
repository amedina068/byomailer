<?php

namespace App\Mcp\Tools;

use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the tags of the account, newest first. Each tag has its id, its name and the number of contacts that have it. Use the id with rename-tag or delete-tag. The list has no pagination.')]
class ListTagsTool extends Tool
{
    protected string $name = 'list-tags';

    public function handle(Request $request): Response|ResponseFactory
    {
        $tags = Tag::query()
            ->withCount('contacts')
            ->latest('id')
            ->get()
            ->map(fn (Tag $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'contacts_count' => $tag->contacts_count,
            ])
            ->all();

        return Response::structured([
            'tags' => $tags,
            'count' => count($tags),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
