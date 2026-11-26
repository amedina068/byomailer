<?php

namespace App\Http\Requests;

use App\Concerns\EmailIdentityValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomLinkDomainRequest extends FormRequest
{
    use EmailIdentityValidationRules;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('custom_link_domain')) {
            $this->merge([
                'custom_link_domain' => $this->normalizeLinkDomain($this->input('custom_link_domain')),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => [
                'required',
                'boolean',
            ],
            'custom_link_domain' => [
                'nullable',
                'required_if:enabled,true',
                ...$this->customLinkDomainRules($this->user()?->client_id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->customLinkDomainMessages(),
            'custom_link_domain.required_if' => 'A link domain is required when enabling a custom link domain.',
        ];
    }
}
