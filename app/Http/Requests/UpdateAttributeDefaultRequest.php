<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeDefaultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'default_value' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Normalize a cleared input to null so `whereNotNull('default_value')`
     * lookups skip it. Strict comparison keeps a literal '0' default intact.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('default_value') === '') {
            $this->merge(['default_value' => null]);
        }
    }
}
