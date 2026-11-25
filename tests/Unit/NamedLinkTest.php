<?php

namespace Tests\Unit;

use App\Concerns\AppliesUtmParameters;
use App\Support\NamedLink;
use PHPUnit\Framework\TestCase;

class NamedLinkTest extends TestCase
{
    use AppliesUtmParameters;

    public function test_normalize_converts_a_name_to_an_ses_safe_slug(): void
    {
        $this->assertSame('black-friday', NamedLink::normalize('  Black Friday! '));
        $this->assertSame('promo-2026', NamedLink::normalize('promo_2026'));
        $this->assertNull(NamedLink::normalize('!!!'));
        $this->assertNull(NamedLink::normalize(''));
        $this->assertNull(NamedLink::normalize(null));
        $this->assertNull(NamedLink::normalize(['black-friday']));
        $this->assertSame(NamedLink::MAX_LENGTH, strlen((string) NamedLink::normalize(str_repeat('a', 300))));
        $this->assertSame(str_repeat('a', 99), NamedLink::normalize(str_repeat('a', 99).' b'));
    }

    public function test_to_ses_tags_replaces_the_attribute_with_an_ses_link_tag(): void
    {
        $html = '<p><a href="https://example.com" data-bm-link="Black Friday">Buy</a> <a href=\'https://example.com/b\' data-bm-link=\'pricing\'>Pricing</a></p>';

        $this->assertSame(
            '<p><a href="https://example.com" ses:tags="bm_link:black-friday;">Buy</a> <a href=\'https://example.com/b\' ses:tags="bm_link:pricing;">Pricing</a></p>',
            NamedLink::toSesTags($html),
        );
    }

    public function test_to_ses_tags_removes_an_attribute_with_no_usable_name(): void
    {
        $this->assertSame(
            '<a href="https://example.com">Buy</a>',
            NamedLink::toSesTags('<a href="https://example.com" data-bm-link="  ">Buy</a>'),
        );
    }

    public function test_to_ses_tags_keeps_dollar_signs_and_backslashes_in_the_content(): void
    {
        $html = '<p>Only $70 or $37/year \\1 ${1}</p><a href="https://example.com" data-bm-link="sale">Buy</a>';

        $this->assertSame(
            '<p>Only $70 or $37/year \\1 ${1}</p><a href="https://example.com" ses:tags="bm_link:sale;">Buy</a>',
            NamedLink::toSesTags($html),
        );
    }

    public function test_to_ses_tags_changes_only_anchor_tags(): void
    {
        $html = '<p>Write data-bm-link="x" on the anchor.</p><div data-bm-link="box">Box</div>';

        $this->assertSame($html, NamedLink::toSesTags($html));
    }

    public function test_the_attribute_survives_the_utm_rewrite(): void
    {
        $html = self::addUtmParametersToHtml(
            '<a href="https://example.com/sale" data-bm-link="sale">Buy</a>',
            ['utm_source' => 'news'],
        );

        $this->assertSame(
            '<a href="https://example.com/sale?utm_source=news" ses:tags="bm_link:sale;">Buy</a>',
            NamedLink::toSesTags($html),
        );
    }

    public function test_names_in_lists_each_normalized_name_one_time(): void
    {
        $html = '<a data-bm-link="Sale">a</a><a data-bm-link="sale">b</a><a data-bm-link="Pricing Page">c</a><a href="#">d</a><span data-bm-link="span">e</span>';

        $this->assertSame(['sale', 'pricing-page'], NamedLink::namesIn($html));
        $this->assertSame([], NamedLink::namesIn(null));
    }

    public function test_from_ses_click_reads_the_link_tag(): void
    {
        $this->assertSame('black-friday', NamedLink::fromSesClick(['linkTags' => ['bm_link' => ['black-friday']]]));
        $this->assertSame('pricing', NamedLink::fromSesClick(['linkTags' => ['bm_link' => 'pricing']]));
        $this->assertNull(NamedLink::fromSesClick(['linkTags' => ['other' => ['x']]]));
        $this->assertNull(NamedLink::fromSesClick(['link' => 'https://example.com']));
    }
}
