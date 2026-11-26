<?php

namespace App\Support\Liquid;

use App\Support\QueryStringJoiner;
use Keepsuit\Liquid\Contracts\NodeVisitor;
use Keepsuit\Liquid\Nodes\BodyNode;
use Keepsuit\Liquid\Nodes\Node;
use Keepsuit\Liquid\Nodes\Variable;
use Keepsuit\Liquid\Nodes\VariableLookup;

/**
 * Add the output filter to the end of each `{{ }}` output of a template.
 *
 * The HTML environment adds `bm_html`, the text environment adds `bm_text`.
 * The query string separator that `Liquid` puts after a link variable is a
 * fixed `?` or `&`. It gets `bm_separator`, so it prints without escaping,
 * the same as before Liquid.
 */
class EscapeOutput implements NodeVisitor
{
    public function __construct(private bool $html) {}

    public function enterNode(Node $node): void
    {
        if (! $node instanceof BodyNode) {
            return;
        }

        $node->setChildren(array_map(
            fn (Node $child): Node => $child::class === Variable::class ? $this->wrap($child) : $child,
            $node->children(),
        ));
    }

    public function leaveNode(Node $node): void {}

    private function wrap(Variable $variable): Variable
    {
        $filter = match (true) {
            ! $this->html => 'bm_text',
            $this->isSeparator($variable) => 'bm_separator',
            default => 'bm_html',
        };

        return new Variable($variable->name, [...$variable->filters, [$filter, [], []]]);
    }

    private function isSeparator(Variable $variable): bool
    {
        return $variable->filters === []
            && $variable->name instanceof VariableLookup
            && str_ends_with($variable->name->toString(), QueryStringJoiner::SEPARATOR_SUFFIX);
    }
}
