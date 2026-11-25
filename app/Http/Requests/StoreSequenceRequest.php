<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectIds;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSequenceRequest extends FormRequest
{
    use ValidatesProjectIds;

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
            'name' => ['required', 'string', 'max:255'],
            ...$this->projectIdRules(),
        ];
    }
}
