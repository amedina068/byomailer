<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomTrackingDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('custom_tracking_domain')) {
            $this->merge([
                'custom_tracking_domain' => strtolower(trim((string) $this->input('custom_tracking_domain'))),
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
            'custom_tracking_domain' => [
                'nullable',
                'required_if:enabled,true',
                'string',
                'max:255',
                'regex:/^([a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/',
                Rule::unique('email_identities', 'custom_tracking_domain')
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
            'custom_tracking_domain.regex' => 'The tracking domain must be a valid hostname (e.g., track.example.com).',
            'custom_tracking_domain.required_if' => 'A tracking domain is required when enabling custom tracking.',
            'custom_tracking_domain.unique' => 'This tracking domain is already in use by another account.',
        ];
    }
}
