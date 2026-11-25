<?php

namespace App\Support;

/**
 * Validation and storage of the content variable defaults of a sequence or a
 * newsletter. A default fills a {{ variable }} when the contact has no value.
 */
class ContentVariables
{
    /**
     * The rules that the dashboard form uses.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'content_variables' => ['nullable', 'array'],
            'content_variables.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Store an empty default as an empty string, like the dashboard form does.
     *
     * @param  array<string, string|null>|null  $submitted
     * @return array<string, string>
     */
    public static function normalize(?array $submitted): array
    {
        return collect($submitted ?? [])
            ->map(fn (?string $value): string => $value ?? '')
            ->all();
    }

    /**
     * Every saved default, plus an empty default for each detected variable
     * that has no saved default yet.
     *
     * @param  array<int, string>  $detected
     * @param  array<string, string|null>|null  $saved
     * @return array<string, string>
     */
    public static function withDetected(array $detected, ?array $saved): array
    {
        return self::normalize($saved) + array_fill_keys($detected, '');
    }
}
