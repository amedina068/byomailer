<?php

namespace App\Support;

trait DetectsTemplateVariables
{
    /**
     * Variables handled automatically by the app (URL injection, slot
     * rendering, etc.) and therefore not exposed to the user as editable
     * template variables.
     *
     * @var array<int, string>
     */
    protected array $reservedTemplateVariables = [
        'content',
        'unsubscribe_url',
        'preferences_url',
        'opt_in_url',
        'postal_address',
    ];

    /**
     * Detect {{variable}} placeholders in the given content.
     *
     * @return array<int, string>
     */
    public function detectVariables(string $content): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $content, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $match): string => trim($match))
            ->filter()
            ->reject(fn (string $name): bool => in_array($name, $this->reservedTemplateVariables, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
