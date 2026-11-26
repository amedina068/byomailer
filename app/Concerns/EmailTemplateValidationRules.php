<?php

namespace App\Concerns;

use App\Rules\ContainsContentSlot;
use App\Rules\SafeHtml;

trait EmailTemplateValidationRules
{
    /**
     * Get the validation rules of an email template. The web form and the MCP
     * tools use them. With $partial, each field is optional, so an update can
     * change one field only.
     *
     * @return array<string, list<mixed>>
     */
    protected function emailTemplateRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$presence, 'string', 'max:255'],
            'html_content' => [$presence, 'string', 'max:524288', new SafeHtml, new ContainsContentSlot],
            'confirmed_no_unsubscribe' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Return the error for template HTML that has no unsubscribe link. The
     * user can save it anyway with an explicit confirmation. Return null when
     * the HTML has the link, is empty, or the user confirmed.
     */
    protected function missingUnsubscribeLinkError(string $html, bool $confirmedNoUnsubscribe): ?string
    {
        if ($html === '' || $confirmedNoUnsubscribe) {
            return null;
        }

        $hasUnsubscribeAnchor = preg_match(
            '/href\s*=\s*["\']\s*\{\{\s*unsubscribe_url\s*\}\}\s*["\']/',
            $html,
        ) === 1;

        if ($hasUnsubscribeAnchor) {
            return null;
        }

        return 'Template is missing an unsubscribe link. Confirm you want to save without one or add a link using the {{ unsubscribe_url }} variable.';
    }
}
