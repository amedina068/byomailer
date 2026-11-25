<?php

namespace App\Rules;

use Closure;
use DOMDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SafeHtml implements ValidationRule
{
    /**
     * Dangerous HTML tags that are not allowed.
     *
     * @var list<string>
     */
    private const FORBIDDEN_TAGS = [
        'script',
        'iframe',
        'object',
        'embed',
        'form',
        'input',
        'textarea',
        'select',
        'button',
        'applet',
        'meta',
        'link',
        'base',
        'svg',
        'math',
    ];

    /**
     * Patterns that indicate dangerous content.
     *
     * A `data:` URL passes only with a raster image type. `data:image/svg+xml`
     * is blocked because an SVG document can carry script.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PATTERNS = [
        '/\bon\w+\s*=/i',
        '/javascript\s*:/i',
        '/vbscript\s*:/i',
        '/data\s*+:\s*+(?!image\/(?:png|jpe?g|gif|webp|avif|bmp)[;,])/i',
        '/expression\s*\(/i',
        '/url\s*\(\s*["\']?\s*javascript/i',
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        foreach (self::FORBIDDEN_TAGS as $tag) {
            if (preg_match('/<\s*\/?\s*'.$tag.'[\s>\/]/i', $value)) {
                $fail('The :attribute contains unsafe HTML. The <'.$tag.'> tag is not allowed.');

                return;
            }
        }

        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                $fail('The :attribute contains unsafe HTML content.');

                return;
            }
        }

        if (! $this->isValidHtml($value)) {
            $fail('The :attribute contains invalid HTML.');
        }
    }

    private function isValidHtml(string $value): bool
    {
        $wrapped = '<div>'.$value.'</div>';

        libxml_use_internal_errors(true);

        $doc = new DOMDocument;
        $result = $doc->loadHTML(
            '<?xml encoding="UTF-8"><body>'.$wrapped.'</body>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
        );

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (! $result) {
            return false;
        }

        foreach ($errors as $error) {
            $message = strtolower(trim($error->message));

            if (str_contains($message, 'tag') && str_contains($message, 'invalid')) {
                return false;
            }

            if (str_contains($message, 'opening and ending tag mismatch')) {
                return false;
            }

            if (str_contains($message, 'unexpected end tag')) {
                return false;
            }
        }

        return true;
    }
}
