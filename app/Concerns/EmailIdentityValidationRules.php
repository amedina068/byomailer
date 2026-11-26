<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait EmailIdentityValidationRules
{
    /**
     * Matches CR, LF, and NUL. These characters let a value inject extra mail headers.
     */
    private const string HEADER_BREAK_PATTERN = '/[\r\n\0]/';

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

    /**
     * Get the validation rules of the Reply-To address of a sender identity.
     * The settings form and the MCP tool use them. An empty email clears the
     * Reply-To address.
     *
     * @return array<string, list<string>>
     */
    protected function replyToRules(): array
    {
        return [
            'reply_to_email' => ['nullable', 'required_with:reply_to_name', 'string', 'max:255', 'not_regex:'.self::HEADER_BREAK_PATTERN, 'email'],
            'reply_to_name' => ['nullable', 'string', 'max:255', 'not_regex:'.self::HEADER_BREAK_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function replyToMessages(): array
    {
        return [
            'reply_to_email.required_with' => 'Add an email address to use a Reply-To name.',
            'reply_to_email.not_regex' => 'The email cannot contain line breaks.',
            'reply_to_name.not_regex' => 'The name cannot contain line breaks.',
        ];
    }

    /**
     * Get the validation rules of the custom link domain of a sender identity.
     * The settings form and the MCP tool use them. A domain is unique across
     * clients, but one client can use its domain on more than one identity.
     *
     * @return list<Unique|string>
     */
    protected function customLinkDomainRules(?int $clientId): array
    {
        return [
            'string',
            'max:255',
            'regex:/^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
            Rule::unique('email_identities', 'custom_link_domain')
                ->where(fn ($query) => $query->whereNotNull('client_id')->where('client_id', '!=', $clientId)),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function customLinkDomainMessages(): array
    {
        return [
            'custom_link_domain.regex' => 'The link domain must be a valid hostname (e.g., mail.example.com).',
            'custom_link_domain.unique' => 'This link domain is already in use by another account.',
        ];
    }

    /**
     * Hostnames are not case-sensitive. The request host arrives in lowercase,
     * so the stored domain must be in lowercase to match.
     */
    protected function normalizeLinkDomain(mixed $domain): mixed
    {
        return is_string($domain) ? Str::lower(trim($domain)) : $domain;
    }
}
