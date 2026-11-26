<?php

namespace App\Actions;

use App\Models\ContactAttribute;

class UpdateAttributeDefault
{
    /**
     * Set the default value of a contact attribute. An empty string clears
     * the default, so `whereNotNull('default_value')` lookups skip it. The
     * strict comparison keeps a literal '0' default.
     */
    public function handle(ContactAttribute $attribute, ?string $defaultValue): ContactAttribute
    {
        $attribute->update(['default_value' => $defaultValue === '' ? null : $defaultValue]);

        return $attribute;
    }
}
