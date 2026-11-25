<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesClientTemplate;
use App\Rules\SafeHtml;
use App\Rules\SafeTemplatedHtml;
use App\Support\ContentSlot;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class NewsletterContentRequest extends FormRequest
{
    use ValidatesClientTemplate;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'preview_text' => ['nullable', 'string', 'max:255', new SafeHtml],
            'html_content' => ['required', 'string', 'max:524288', new SafeTemplatedHtml],
            'email_template_id' => ['nullable', 'integer', $this->clientTemplateExistsRule()],
            'template_slot_content' => ['nullable', 'string'],
            'ab_test_enabled' => ['sometimes', 'boolean'],
            'subject_b' => ['required_if:ab_test_enabled,true', 'nullable', 'string', 'max:255'],
            'preview_text_b' => ['nullable', 'string', 'max:255', new SafeHtml],
            'html_content_b' => ['required_if:ab_test_enabled,true', 'nullable', 'string', 'max:524288', new SafeTemplatedHtml],
            'template_slot_content_b' => ['nullable', 'string'],
        ];
    }

    /**
     * The form sends the whole content step. A field that the form leaves out
     * is empty, so it clears the stored value.
     *
     * @return array<string, mixed>
     */
    public function contentChanges(): array
    {
        return [
            'title' => $this->validated('title'),
            'subject' => $this->validated('subject'),
            'preview_text' => $this->validated('preview_text'),
            'html_content' => $this->validated('html_content'),
            'email_template_id' => $this->validated('email_template_id'),
            'ab_test_enabled' => (bool) $this->validated('ab_test_enabled', false),
            'subject_b' => $this->validated('subject_b'),
            'preview_text_b' => $this->validated('preview_text_b'),
            'html_content_b' => $this->validated('html_content_b'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('email_template_id') === null) {
                return;
            }

            $htmlA = (string) $this->input('html_content', '');

            if (! ContentSlot::hasWrapper($htmlA)) {
                $validator->errors()->add(
                    'html_content',
                    'Message must keep the template content slot. Re-apply the template or edit inside the slot.',
                );
            }

            if (! $this->boolean('ab_test_enabled')) {
                return;
            }

            $htmlB = (string) $this->input('html_content_b', '');

            if (! ContentSlot::hasWrapper($htmlB)) {
                $validator->errors()->add(
                    'html_content_b',
                    'Variant B must keep the template content slot. Re-apply the template or edit inside the slot.',
                );
            }
        });
    }
}
