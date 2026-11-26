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
     * Detect the variables of the given content: the {{variable}} placeholders,
     * and for Liquid content each variable that the Liquid code reads.
     *
     * @return array<int, string>
     */
    public function detectVariables(string $content): array
    {
        if ($content === '') {
            return [];
        }

        if (Liquid::isPlain($content) || Liquid::error($content) !== null) {
            preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $content, $matches);
            $names = $matches[1] ?? [];
        } else {
            $names = Liquid::variables($content);
        }

        return collect($names)
            ->map(fn (string $match): string => trim($match))
            ->filter()
            ->reject(fn (string $name): bool => in_array($name, $this->reservedTemplateVariables, true))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
