<?php

namespace App\Mcp\Tools;

use App\Models\ContactAttribute;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the custom contact attribute keys of the account, sorted by name, with the default value of each key. A {{ variable }} placeholder with the same name shows the default value when a contact has no value for the key. default_value is null when the key has no default. Use the id with update-contact-attribute-default.')]
class ListContactAttributesTool extends Tool
{
    protected string $name = 'list-contact-attributes';

    public function handle(Request $request): Response|ResponseFactory
    {
        $attributes = ContactAttribute::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ContactAttribute $attribute): array => [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'default_value' => $attribute->default_value,
            ])
            ->all();

        return Response::structured([
            'attributes' => $attributes,
            'count' => count($attributes),
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
