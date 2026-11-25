<?php

namespace App\Http\Requests\Api;

use App\Enums\IdentityStatus;
use App\Http\Requests\Concerns\ValidatesNewsletterFilters;
use App\Http\Requests\Concerns\ValidatesProjectIds;
use App\Rules\NotInPast;
use App\Rules\SafeHtml;
use App\Rules\SafeTemplatedHtml;
use App\Rules\ValidLiquid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreNewsletterRequest extends FormRequest
{
    use ValidatesNewsletterFilters;
    use ValidatesProjectIds;

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
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subject' => ['required_if:status,live', 'nullable', 'string', 'max:255', new ValidLiquid],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255', new SafeHtml, new ValidLiquid],
            'html_content' => ['required_if:status,live', 'nullable', 'string', 'max:524288', new SafeTemplatedHtml, new ValidLiquid],
            'status' => ['sometimes', 'string', 'in:draft,live'],
            'scheduled_at' => ['required_if:status,live', 'prohibited_unless:status,live', 'nullable', 'date', new NotInPast],
            'email_identity_id' => [
                'required_if:status,live',
                'nullable',
                'integer',
                Rule::exists('email_identities', 'id')
                    ->where('client_id', $this->user()?->client_id)
                    ->where('identity_status', IdentityStatus::Verified->value),
            ],
            'email_template_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('email_templates', 'id')
                    ->where('client_id', $this->user()?->client_id),
            ],
            'ab_test_enabled' => ['sometimes', 'boolean'],
            'subject_b' => ['required_if:ab_test_enabled,true', 'nullable', 'string', 'max:255'],
            'preview_text_b' => ['sometimes', 'nullable', 'string', 'max:255', new SafeHtml],
            'html_content_b' => ['required_if:ab_test_enabled,true', 'nullable', 'string', 'max:524288', new SafeTemplatedHtml],
            'content_variables' => ['sometimes', 'nullable', 'array'],
            'content_variables.*' => ['nullable', 'string', 'max:255'],
            'filters' => ['sometimes', 'array', 'max:20'],
            ...$this->filterItemRules(),
            ...$this->projectIdRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateFilterOwnership($validator);
        });
    }
}
