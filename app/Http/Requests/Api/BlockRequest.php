<?php

namespace App\Http\Requests\Api;

use App\Rules\SafeHtml;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clientId = $this->user()?->client_id ?? 0;
        $blockId = $this->route('blockId') ?? null;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('blocks', 'name')
                    ->where(fn ($query) => $query->where('client_id', $clientId))
                    ->ignore($blockId),
            ],
            'html_content' => ['required', 'string', new SafeHtml],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name may only contain lowercase letters, numbers, and underscores.',
        ];
    }
}
