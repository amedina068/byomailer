<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EnrollContactsInSequenceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sequence_id' => ['required', 'integer'],
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sequence_id.required' => 'Select a sequence to add contacts to.',
            'contact_ids.required' => 'Select at least one contact.',
            'contact_ids.min' => 'Select at least one contact.',
        ];
    }
}
