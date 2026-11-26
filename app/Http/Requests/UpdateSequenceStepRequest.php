<?php

namespace App\Http\Requests;

use App\Enums\DelayUnit;
use App\Http\Requests\Concerns\ValidatesClientTemplate;
use App\Rules\SafeHtml;
use App\Rules\ValidLiquid;
use App\Support\ContentSlot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSequenceStepRequest extends FormRequest
{
    use ValidatesClientTemplate;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientId = $this->user()?->client_id ?? 0;

        return [
            'delay_type' => ['required', Rule::enum(DelayUnit::class)],
            'delay_days' => ['required', 'integer', 'min:0', 'max:'.DelayUnit::maxDelayFor($this->input('delay_type'))],
            'subject' => ['nullable', 'string', 'max:255', new ValidLiquid],
            'preview_text' => ['nullable', 'string', 'max:255', new SafeHtml, new ValidLiquid],
            'content' => ['nullable', 'string', 'max:524288', new SafeHtml, new ValidLiquid],
            'email_template_id' => ['nullable', 'integer', $this->clientTemplateExistsRule()],
            'template_slot_content' => ['nullable', 'string'],
            'email_identity_id' => [
                'required',
                'integer',
                Rule::exists('email_identities', 'id')->where(fn ($query) => $query->where('client_id', $clientId)),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('email_template_id') === null) {
                return;
            }

            $html = (string) $this->input('content', '');

            if (! ContentSlot::hasWrapper($html)) {
                $validator->errors()->add(
                    'content',
                    'Message must keep the template content slot. Re-apply the template or edit inside the slot.',
                );
            }
        });
    }
}
