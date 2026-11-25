<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesNewsletterFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSegmentRequest extends FormRequest
{
    use ValidatesNewsletterFilters;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('segments')
                    ->where('client_id', $this->user()->client_id)
                    ->ignore($this->route('segment')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'filters' => ['present', 'array', 'min:1', 'max:20'],
            ...$this->filterItemRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a segment with this name.',
            'filters.min' => 'A segment needs at least one filter.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateFilterOwnership($validator);
        });
    }
}
