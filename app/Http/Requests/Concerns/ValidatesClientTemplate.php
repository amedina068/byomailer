<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesClientTemplate
{
    /**
     * Ensure the referenced email template belongs to the current user's client.
     * Prevents cross-tenant template application via request spoofing.
     */
    protected function clientTemplateExistsRule(): Exists
    {
        $clientId = $this->user()?->client_id ?? 0;

        return Rule::exists('email_templates', 'id')->where(function ($query) use ($clientId): void {
            $query->where('client_id', $clientId)->whereNull('archived_at');
        });
    }
}
