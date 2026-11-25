<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateApiTokenRequest extends FormRequest
{
    /**
     * The token abilities of each access choice.
     *
     * @var array<string, list<string>>
     */
    public const ACCESS_ABILITIES = [
        'read' => ['read'],
        'read_write' => ['read', 'write'],
    ];

    /**
     * @var list<int>
     */
    public const EXPIRATION_DAYS = [30, 90, 365];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'access' => ['required', 'string', Rule::in(array_keys(self::ACCESS_ABILITIES))],
            'expires_in_days' => ['nullable', 'integer', Rule::in(self::EXPIRATION_DAYS)],
        ];
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return self::ACCESS_ABILITIES[$this->validated('access')];
    }
}
