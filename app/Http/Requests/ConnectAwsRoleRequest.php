<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConnectAwsRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'aws_account_id' => ['required', 'string', 'regex:/^[0-9]{12}$/'],
            'region' => ['required', 'string', 'regex:/^[a-z]{2}-[a-z0-9-]+-[0-9]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'aws_account_id.regex' => 'Enter the 12-digit ID of your AWS account.',
            'region.regex' => 'The region must be a valid AWS region (e.g., us-east-1).',
        ];
    }
}
