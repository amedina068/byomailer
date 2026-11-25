<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesNewsletterFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SequenceStepFiltersRequest extends FormRequest
{
    use ValidatesNewsletterFilters;

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
            'filters' => ['present', 'array', 'max:20'],
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
