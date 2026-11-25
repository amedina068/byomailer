<?php

namespace App\Http\Requests\Api;

use App\Support\ContentVariables;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContentVariablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...ContentVariables::rules(),
            'content_variables' => ['present', 'nullable', 'array'],
        ];
    }
}
