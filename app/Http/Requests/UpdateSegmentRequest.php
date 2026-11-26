<?php

namespace App\Http\Requests;

use App\Concerns\SegmentValidationRules;
use App\Http\Requests\Concerns\ValidatesNewsletterFilters;
use App\Models\Segment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSegmentRequest extends FormRequest
{
    use SegmentValidationRules;
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
        /** @var Segment $segment */
        $segment = $this->route('segment');

        return [
            ...$this->segmentRules($this->user()->client_id, $segment),
            ...$this->filterItemRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->segmentMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateFilterOwnership($validator);
        });
    }
}
