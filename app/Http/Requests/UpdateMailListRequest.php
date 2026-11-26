<?php

namespace App\Http\Requests;

use App\Concerns\MailListValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailListRequest extends FormRequest
{
    use MailListValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->mailListRules($this->user()->client_id, $this->route('list')),
            ...$this->mailListOptInRules($this->user()->client_id),
        ];
    }
}
