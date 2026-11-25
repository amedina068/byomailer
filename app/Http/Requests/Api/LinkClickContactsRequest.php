<?php

namespace App\Http\Requests\Api;

use App\Services\LinkClickReport;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class LinkClickContactsRequest extends FormRequest
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
        return [
            /** A link key from the link list, for example `name:black-friday` or `url:https://example.com/sale`. */
            'link' => ['required', 'string', 'max:2048', function (string $attribute, mixed $value, Closure $fail): void {
                if (! LinkClickReport::isValidKey($value)) {
                    $fail('The link must be a key from the link list.');
                }
            }],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
