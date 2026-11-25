<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait EmailIdentityValidationRules
{
    /**
     * Get the validation rules used to create a sender identity. The web form
     * and the MCP tool both use them. An email is unique in the client, as the
     * `(client_id, email)` index requires.
     *
     * @return array<string, array<int, Unique|string>>
     */
    protected function emailIdentityRules(User $user): array
    {
        return [
            'email' => [
                'required',
                'email',
                Rule::unique('email_identities', 'email')->where('client_id', $user->client_id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'existing_aws_identity' => ['sometimes', 'boolean'],
            'open_tracking_enabled' => ['sometimes', 'boolean'],
            'click_tracking_enabled' => ['sometimes', 'boolean'],
            'mail_from_subdomain' => [
                'nullable',
                'string',
                'max:253',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
            ],
        ];
    }
}
