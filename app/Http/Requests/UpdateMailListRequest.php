<?php

namespace App\Http\Requests;

use App\Concerns\MailListValidationRules;
use App\Rules\ValidLiquid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'opt_in_enabled' => ['boolean'],
            'opt_in_subject' => ['required_if:opt_in_enabled,true', 'nullable', 'string', 'max:255', new ValidLiquid],
            'opt_in_html_content' => ['required_if:opt_in_enabled,true', 'nullable', 'string', new ValidLiquid],
            'opt_in_email_identity_id' => [
                'required_if:opt_in_enabled,true',
                'nullable',
                'integer',
                Rule::exists('email_identities', 'id')->where('client_id', $this->user()->client_id),
            ],
        ];
    }
}
