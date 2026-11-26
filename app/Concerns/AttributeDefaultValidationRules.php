<?php

namespace App\Concerns;

trait AttributeDefaultValidationRules
{
    /**
     * Get the validation rules of the default value of a contact attribute.
     * The web form and the MCP tool use them.
     *
     * @return array<string, array<int, string>>
     */
    protected function attributeDefaultRules(): array
    {
        return [
            'default_value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
