<?php

namespace App\Http\Requests\Concerns;

use App\Rules\ContainsContentSlot;
use App\Rules\SafeHtml;
use App\Rules\ValidLiquid;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesEmailTemplate
{
    /**
     * Shared validation rules for create/update email template requests.
     *
     * @return array<string, mixed>
     */
    protected function emailTemplateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'html_content' => ['required', 'string', new SafeHtml, new ContainsContentSlot, new ValidLiquid],
            'confirmed_no_unsubscribe' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Soft unsubscribe guard: saves without an unsubscribe link are rejected
     * unless the client explicitly confirms via `confirmed_no_unsubscribe=1`.
     */
    protected function enforceUnsubscribeGuard(Validator $validator): void
    {
        $html = (string) $this->input('html_content', '');

        if ($html === '') {
            return;
        }

        $hasUnsubscribeAnchor = preg_match(
            '/href\s*=\s*["\']\s*\{\{\s*unsubscribe_url\s*\}\}\s*["\']/',
            $html,
        ) === 1;

        if ($hasUnsubscribeAnchor) {
            return;
        }

        if ($this->boolean('confirmed_no_unsubscribe')) {
            return;
        }

        $validator->errors()->add(
            'html_content',
            'Template is missing an unsubscribe link. Confirm you want to save without one or add a link using the {{ unsubscribe_url }} variable.',
        );
    }
}
