<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ValidateAwsCredentialsRequest extends FormRequest
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

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'access_key.regex' => 'The access key must be a valid AWS Access Key ID (16-32 alphanumeric characters).',
            'secret_key.min' => 'The secret key must be at least 40 characters.',
            'region.regex' => 'The region must be a valid AWS region (e.g., us-east-1).',
        ];
    }
}
