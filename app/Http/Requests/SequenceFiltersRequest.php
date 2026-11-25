<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesNewsletterFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SequenceFiltersRequest extends FormRequest
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
            'filters' => ['present', 'array', 'max:20'],
            'segment_id' => [
                'nullable',
                Rule::exists('segments', 'id')->where('client_id', $this->user()->client_id),
            ],
            ...$this->filterItemRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateFilterOwnership($validator);
        });
    }
}
