<?php

namespace App\Support;

trait ReplacesTemplateVariables
{
    /**
     * Replace {{variable}} placeholders in the given content with values from the data map.
     * A query string after a placeholder is joined with `&` when the value already has `?`.
     *
     * @param  array<string, mixed>  $data
     */
    public function replaceTemplateVariables(string $content, array $data): string
    {
        if ($data === []) {
            return $content;
        }

        foreach ($data as $key => $value) {
            $content = QueryStringJoiner::replacePlaceholder($content, (string) $key, (string) $value);
        }

        return $content;
    }

    /**
     * Replace {{variable}} placeholders in HTML. Each value is HTML-escaped,
     * because contact data must not add markup to the email. Use
     * replaceTemplateVariables() for plain text, such as a subject.
     *
     * @param  array<string, mixed>  $data
     */
    public function replaceTemplateVariablesInHtml(string $content, array $data): string
    {
        return $this->replaceTemplateVariables(
            $content,
            array_map(fn (mixed $value): string => e((string) $value), $data),
        );
    }
}
