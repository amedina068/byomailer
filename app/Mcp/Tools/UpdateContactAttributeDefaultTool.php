<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateAttributeDefault;
use App\Concerns\AttributeDefaultValidationRules;
use App\Models\ContactAttribute;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Set or clear the default value of one custom contact attribute key. A {{ variable }} placeholder with the same name shows the default value when a contact has no value for the key. Send null or an empty string to clear the default. The contact values do not change. Find attribute ids with list-contact-attributes.')]
class UpdateContactAttributeDefaultTool extends Tool
{
    use AttributeDefaultValidationRules;

    protected string $name = 'update-contact-attribute-default';

    public function handle(Request $request, UpdateAttributeDefault $updateAttributeDefault): Response|ResponseFactory
    {
        $rules = $this->attributeDefaultRules();

        $validated = $request->validate([
            'attribute_id' => ['required', 'integer'],
            'default_value' => ['present', ...$rules['default_value']],
        ]);

        $attribute = ContactAttribute::query()->find($validated['attribute_id']);

        if ($attribute === null) {
            return Response::error("No contact attribute found with id {$validated['attribute_id']}.");
        }

        $updateAttributeDefault->handle($attribute, $validated['default_value']);

        return Response::structured([
            'id' => $attribute->id,
            'name' => $attribute->name,
            'default_value' => $attribute->default_value,
            'message' => $attribute->default_value === null
                ? "The default value of attribute \"{$attribute->name}\" is cleared."
                : "The default value of attribute \"{$attribute->name}\" is saved.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'attribute_id' => $schema->integer()
                ->description('Id of the attribute key. Use list-contact-attributes to find attribute ids.')
                ->required(),
            'default_value' => $schema->string()
                ->nullable()
                ->description('The new default value, at most 255 characters. Send null or an empty string to clear the default.')
                ->required(),
        ];
    }
}
