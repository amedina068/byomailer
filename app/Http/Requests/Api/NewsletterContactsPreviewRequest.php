<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\ValidatesNewsletterFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class NewsletterContactsPreviewRequest extends FormRequest
{
    use ValidatesNewsletterFilters;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filters' => ['sometimes', 'array', 'max:20'],
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
