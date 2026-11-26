<?php

namespace App\Support;

use App\Support\Liquid\EmailExtension;
use App\Support\Liquid\RenderLimits;
use Generator;
use Keepsuit\Liquid\Contracts\HasParseTreeVisitorChildren;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Keepsuit\Liquid\Exceptions\UndefinedFilterException;
use Keepsuit\Liquid\Nodes\Node;
use Keepsuit\Liquid\Nodes\RangeLookup;
use Keepsuit\Liquid\Nodes\Variable;
use Keepsuit\Liquid\Nodes\VariableLookup;
use Keepsuit\Liquid\ParsedTemplate;
use Keepsuit\Liquid\Tags\AssignTag;
use Keepsuit\Liquid\Tags\CaptureTag;
use Keepsuit\Liquid\Tags\ForTag;
use Keepsuit\Liquid\Tags\IncrementTag;
use Keepsuit\Liquid\Tags\TableRowTag;

/**
 * The Liquid engine of email content: subjects, preview texts and bodies.
 *
 * Content is not trusted. The engine has no file system, so `render` and
 * `include` cannot read a file. The data holds only text, numbers and lists,
 * never a PHP object. Each render has a time limit, an output limit and a
 * limit on loops. In HTML, each `{{ }}` output is escaped, so contact data
 * cannot add markup.
 *
 * Content that uses only `{{ name }}` placeholders is "plain". The send code
 * from before Liquid still renders plain content, so its output stays the same.
 */
class Liquid
{
    public const MAX_OUTPUT_BYTES = 524288;

    public const MAX_RENDER_SCORE = 100000;

    public const MAX_RENDER_SECONDS = 2.0;

    public const MAX_RANGE_LENGTH = 1000;

    /**
     * `render` reads files. `echo`, `liquid` and `cycle` print values that
     * the output escaping does not see.
     *
     * @var list<string>
     */
    private const REMOVED_TAGS = ['render', 'echo', 'liquid', 'cycle'];

    private const MAX_CACHED_TEMPLATES = 64;

    /**
     * @var array<string, Environment>
     */
    private static array $environments = [];

    /**
     * @var array<string, ParsedTemplate>
     */
    private static array $templates = [];

    /**
     * True when each `{{ }}` of the content is a `{{ name }}` placeholder and
     * the content has no `{% %}` tag.
     */
    public static function isPlain(string $content): bool
    {
        if (str_contains($content, '{%')) {
            return false;
        }

        preg_match_all('/\{\{.*?\}\}/s', $content, $matches);

        foreach ($matches[0] as $output) {
            if (preg_match('/^\{\{\s*[A-Za-z0-9_.-]+\s*\}\}$/', $output) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render the content with the template data of one contact. The keys of
     * the data can be paths, for example `address.city`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws LiquidException
     */
    public static function render(string $content, array $data, bool $html = true): string
    {
        $environment = self::environment($html);

        return self::parse($content, $html)->render($environment->newRenderContext(
            data: self::nest($data),
            resourceLimits: new RenderLimits(self::MAX_RENDER_SECONDS, self::MAX_OUTPUT_BYTES, self::MAX_RENDER_SCORE, self::MAX_OUTPUT_BYTES),
        ));
    }

    /**
     * A message for the author when the content is not valid Liquid. Plain
     * content is always valid.
     */
    public static function error(string $content): ?string
    {
        if (self::isPlain($content)) {
            return null;
        }

        try {
            self::parse($content, html: true);
        } catch (LiquidException $exception) {
            return $exception->lineNumber
                ? "The Liquid code has an error on line {$exception->lineNumber}: {$exception->getMessage()}"
                : "The Liquid code has an error: {$exception->getMessage()}";
        }

        return null;
    }

    /**
     * The variables that the content reads, as paths like `name` or
     * `address.city`. Loop variables and assigned variables are not in the
     * list.
     *
     * @return list<string>
     *
     * @throws LiquidException
     */
    public static function variables(string $content): array
    {
        $paths = [];
        $bound = ['forloop', 'tablerowloop', 'block'];

        foreach (self::nodes(self::parse($content, html: true)->root) as $node) {
            if ($node instanceof VariableLookup) {
                $paths[] = self::path($node);
            } elseif ($name = self::boundName($node)) {
                $bound[] = $name;
            }
        }

        $paths = array_values(array_unique(array_filter(
            $paths,
            fn (string $path): bool => ! in_array(explode('.', $path)[0], $bound, true),
        )));

        // A list of `address` and `address.city` reads `address` only. The
        // value of `address` already holds the city.
        $paths = array_filter($paths, fn (string $path): bool => ! collect($paths)->contains(
            fn (string $other): bool => str_starts_with($path, $other.'.'),
        ));

        sort($paths);

        return $paths;
    }

    /**
     * @throws LiquidException
     */
    private static function parse(string $content, bool $html): ParsedTemplate
    {
        $source = self::prepare($content, $html);
        $key = ($html ? 'html:' : 'text:').hash('xxh128', $source);

        if (isset(self::$templates[$key])) {
            return self::$templates[$key];
        }

        $template = self::environment($html)->parseString($source);
        self::assertSafe($template, self::environment($html));

        if (count(self::$templates) >= self::MAX_CACHED_TEMPLATES) {
            array_shift(self::$templates);
        }

        return self::$templates[$key] = $template;
    }

    /**
     * The HTML editor stores `>` as `&gt;` and a quote as `&quot;`. Inside a
     * Liquid delimiter, they are code, so they are decoded before the parse.
     * A query string after a link variable gets the separator of the old
     * replacement: `?`, or `&` when the value already has a query string.
     */
    private static function prepare(string $content, bool $html): string
    {
        if ($html) {
            $content = preg_replace_callback(
                '/\{\{.*?\}\}|\{%.*?%\}/s',
                fn (array $match): string => str_replace("\u{00A0}", ' ', html_entity_decode($match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                $content,
            ) ?? $content;
        }

        return preg_replace_callback(
            '/\{\{\s*([\w.-]+)\s*\}\}\?(?=[\w.~%\-\[\]]+=)/',
            fn (array $match): string => '{{ '.$match[1].' }}{{ '.$match[1].QueryStringJoiner::SEPARATOR_SUFFIX.' }}',
            $content,
        ) ?? $content;
    }

    /**
     * @throws LiquidException
     */
    private static function assertSafe(ParsedTemplate $template, Environment $environment): void
    {
        foreach (self::nodes($template->root) as $node) {
            if ($node instanceof RangeLookup && ! self::isSmallRange($node)) {
                throw new SyntaxException('A range must use two whole numbers and hold '.self::MAX_RANGE_LENGTH.' numbers or less, for example (1..5).');
            }

            if ($node instanceof Variable) {
                foreach ($node->filters as [$filter]) {
                    if (! $environment->filterRegistry->has($filter)) {
                        throw new UndefinedFilterException($filter);
                    }
                }
            }
        }
    }

    private static function isSmallRange(RangeLookup $range): bool
    {
        return is_int($range->start) && is_int($range->end)
            && abs($range->end - $range->start) < self::MAX_RANGE_LENGTH;
    }

    /**
     * Each node of the parse tree, with the expressions inside the tags.
     *
     * @return Generator<int, mixed>
     */
    private static function nodes(mixed $node): Generator
    {
        yield $node;

        $children = match (true) {
            $node instanceof HasParseTreeVisitorChildren => $node->parseTreeVisitorChildren(),
            $node instanceof Node => $node->children(),
            is_array($node) => $node,
            default => [],
        };

        foreach ($children as $child) {
            yield from self::nodes($child);
        }
    }

    private static function path(VariableLookup $lookup): string
    {
        $path = $lookup->name;

        foreach ($lookup->lookups as $key) {
            if (! is_string($key) || in_array($key, VariableLookup::FILTER_METHODS, true)) {
                break;
            }

            $path .= '.'.$key;
        }

        return $path;
    }

    /**
     * The variable that a tag sets, for example `item` in `{% for item in list %}`.
     */
    private static function boundName(mixed $node): ?string
    {
        return match (true) {
            $node instanceof AssignTag, $node instanceof CaptureTag => (fn (): string => $this->to)->call($node),
            $node instanceof ForTag, $node instanceof TableRowTag,
            $node instanceof IncrementTag => (fn (): string => $this->variableName)->call($node),
            default => null,
        };
    }

    /**
     * Turn the flat template data into nested Liquid data. A key `address.city`
     * becomes `address` with the key `city`. A value that is not text, a
     * number or a list is dropped, so a template cannot reach a PHP object.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function nest(array $data): array
    {
        $data = array_filter(
            $data,
            fn (mixed $value, string|int $key): bool => ! str_ends_with((string) $key, QueryStringJoiner::SEPARATOR_SUFFIX),
            ARRAY_FILTER_USE_BOTH,
        );
        $data = QueryStringJoiner::withSeparators($data);

        uksort($data, fn (string|int $a, string|int $b): int => substr_count((string) $a, '.') <=> substr_count((string) $b, '.'));

        $nested = [];

        foreach ($data as $key => $value) {
            $segments = explode('.', (string) $key);
            $last = array_pop($segments);
            $target = &$nested;

            foreach ($segments as $segment) {
                $target[$segment] ??= [];

                if (! is_array($target[$segment])) {
                    unset($target);

                    continue 2;
                }

                $target = &$target[$segment];
            }

            $target[$last] ??= self::clean($value);
            unset($target);
        }

        return $nested;
    }

    private static function clean(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::clean(...), $value);
        }

        return is_scalar($value) ? $value : null;
    }

    private static function environment(bool $html): Environment
    {
        $key = $html ? 'html' : 'text';

        if (isset(self::$environments[$key])) {
            return self::$environments[$key];
        }

        $environment = EnvironmentFactory::new()
            ->setRethrowErrors(true)
            ->setStrictVariables(false)
            ->setStrictFilters(true)
            ->addExtension(new EmailExtension($html))
            ->build();

        foreach (self::REMOVED_TAGS as $tag) {
            $environment->tagRegistry->delete($tag);
        }

        return self::$environments[$key] = $environment;
    }
}
