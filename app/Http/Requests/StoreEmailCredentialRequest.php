<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmailCredentialRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'access_key' => [
                'required',
                'string',
                'regex:/^[A-Z0-9]{16,32}$/',
            ],
            'secret_key' => [
                'required',
                'string',
                'min:40',
                'max:128',
            ],
            'region' => [
                'required',
                'string',
                'regex:/^[a-z]{2}-[a-z0-9-]+-[0-9]+$/',
            ],
        ];
    }
}
