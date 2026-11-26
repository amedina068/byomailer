<?php

namespace App\Concerns;

use App\Models\MailList;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

trait MailListValidationRules
{
    /**
     * Get the validation rules of the list name. The web forms, the API and
     * the MCP tools use them. A name is unique in the client. The database
     * has a unique index on `client_id` and `name`.
     *
     * @return array<string, array<int, Unique|string>>
     */
    protected function mailListRules(int $clientId, int|string|null $ignoreListId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(MailList::class, 'name')
                    ->where('client_id', $clientId)
                    ->ignore($ignoreListId),
            ],
        ];
    }

    /**
     * Get the validation rules of the opt-in settings of a list. The web edit
     * form and the MCP tool use them. When opt-in is on, the opt-in email
     * needs a subject, a body and a sender identity of the client.
     *
     * @return array<string, array<int, Exists|string>>
     */
    protected function mailListOptInRules(int $clientId): array
    {
        return [
            'opt_in_enabled' => ['boolean'],
            'opt_in_subject' => ['required_if:opt_in_enabled,true', 'nullable', 'string', 'max:255'],
            'opt_in_html_content' => ['required_if:opt_in_enabled,true', 'nullable', 'string'],
            'opt_in_email_identity_id' => [
                'required_if:opt_in_enabled,true',
                'nullable',
                'integer',
                Rule::exists('email_identities', 'id')->where('client_id', $clientId),
            ],
        ];
    }
}
