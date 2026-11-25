<?php

namespace Tests\Unit;

use App\Support\QueryStringJoiner;
use PHPUnit\Framework\TestCase;

class QueryStringJoinerTest extends TestCase
{
    public function test_replace_placeholder_keeps_question_mark_when_value_has_no_query(): void
    {
        $result = QueryStringJoiner::replacePlaceholder('{{ checkout_url }}?coupon=KEEPIT57', 'checkout_url', 'https://shop.test/checkout');

        $this->assertSame('https://shop.test/checkout?coupon=KEEPIT57', $result);
    }

    public function test_replace_placeholder_uses_ampersand_when_value_already_has_query(): void
    {
        $result = QueryStringJoiner::replacePlaceholder('{{ checkout_url }}?coupon=KEEPIT57', 'checkout_url', 'https://shop.test/checkout?token=abc&plan=lifetime');

        $this->assertSame('https://shop.test/checkout?token=abc&plan=lifetime&coupon=KEEPIT57', $result);
    }

    public function test_replace_placeholder_leaves_question_mark_in_prose(): void
    {
        $result = QueryStringJoiner::replacePlaceholder('Are you {{ name }}? Great to see you.', 'name', 'Who?');

        $this->assertSame('Are you Who?? Great to see you.', $result);
    }

    public function test_replace_placeholder_does_not_treat_value_as_regex_backreference(): void
    {
        $result = QueryStringJoiner::replacePlaceholder('Price: {{ price }}', 'price', '$1.00 \\0');

        $this->assertSame('Price: $1.00 \\0', $result);
    }

    public function test_rewrite_for_template_adds_raw_separator_placeholder(): void
    {
        $result = QueryStringJoiner::rewriteForTemplate('<a href="{{ checkout_url }}?coupon=X">Buy</a> Are you {{ name }}? Yes.');

        $this->assertSame('<a href="{{ checkout_url }}{{{ checkout_url__qs }}}coupon=X">Buy</a> Are you {{ name }}? Yes.', $result);
    }

    public function test_with_separators_adds_one_separator_per_scalar_value(): void
    {
        $result = QueryStringJoiner::withSeparators([
            'plain' => 'https://shop.test/checkout',
            'with_query' => 'https://shop.test/checkout?token=abc',
            'empty' => '',
            'missing' => null,
            'list' => ['a', 'b'],
            'already__qs' => '&',
        ]);

        $this->assertSame('?', $result['plain__qs']);
        $this->assertSame('&', $result['with_query__qs']);
        $this->assertSame('?', $result['empty__qs']);
        $this->assertSame('?', $result['missing__qs']);
        $this->assertArrayNotHasKey('list__qs', $result);
        $this->assertArrayNotHasKey('already__qs__qs', $result);
    }
}
