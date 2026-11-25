<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateCustomLinkDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Hostnames are case-insensitive; the request host arrives lowercased,
     * so the stored domain must be lowercased to match.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->custom_link_domain)) {
            $this->merge([
                'custom_link_domain' => Str::lower(trim($this->custom_link_domain)),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientId = $this->user()?->client_id;

        return [
            'enabled' => [
                'required',
                'boolean',
            ],
            'custom_link_domain' => [
                'nullable',
                'required_if:enabled,true',
                'string',
                'max:255',
                'regex:/^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
                Rule::unique('email_identities', 'custom_link_domain')
                    ->where(fn ($query) => $query->whereNotNull('client_id')->where('client_id', '!=', $clientId)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custom_link_domain.regex' => 'The link domain must be a valid hostname (e.g., mail.example.com).',
            'custom_link_domain.required_if' => 'A link domain is required when enabling a custom link domain.',
            'custom_link_domain.unique' => 'This link domain is already in use by another account.',
        ];
    }
}
