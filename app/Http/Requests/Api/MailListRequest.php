<?php

namespace App\Http\Requests\Api;

use App\Concerns\MailListValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class MailListRequest extends FormRequest
{
    use MailListValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The API creates a list with no `listId` and renames a list with a
     * `listId`. Scramble calls this method with no user when it builds the
     * API documentation. The fallback client id keeps that call working.
     *
     * @return array<string, array<int, Unique|string>>
     */
    public function rules(): array
    {
        return $this->mailListRules($this->user()->client_id ?? 0, $this->route('listId'));
    }
}
