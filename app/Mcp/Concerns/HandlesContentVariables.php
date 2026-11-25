<?php

namespace App\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

trait HandlesContentVariables
{
    protected function contentVariablesSchema(JsonSchema $schema): Type
    {
        return $schema->object()
            ->description('Default values of the {{ variable }} placeholders, as an object of variable name to text (maximum 255 characters each). A default fills the placeholder when the contact has no value for it, for example {"checkout_url": "https://example.com/checkout"}. The object replaces all saved defaults. An empty object clears them.');
    }
}
