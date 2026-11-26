<?php

namespace Tests\Unit;

use App\Support\Liquid;
use App\Support\Liquid\RenderLimits;
use Keepsuit\Liquid\Exceptions\LiquidException;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

class LiquidTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function plainContent(): array
    {
        return [
            'placeholders' => ['Hi {{ name }} and {{first_name}} in {{ address.city }} {{ first-name }} {{ block.footer }}', true],
            'no placeholder' => ['<p>Hello</p>', true],
            'a tag' => ['{% if vip %}VIP{% endif %}', false],
            'a filter' => ['{{ name | upcase }}', false],
            'two words' => ['{{ first name }}', false],
            'triple braces' => ['{{{ name }}}', false],
            'handlebars' => ['{{#if vip}}VIP{{/if}}', false],
        ];
    }

    #[DataProvider('plainContent')]
    public function test_it_tells_plain_content_from_liquid(string $content, bool $plain): void
    {
        $this->assertSame($plain, Liquid::isPlain($content));
    }

    public function test_it_renders_conditions_loops_and_filters(): void
    {
        $html = Liquid::render(
            '{% if vip == "yes" %}VIP {% endif %}{{ name | upcase }}: {% for item in items %}[{{ item }}]{% endfor %} {{ nickname | default: "friend" }}',
            ['vip' => 'yes', 'name' => 'ann', 'items' => ['a', 'b']],
        );

        $this->assertSame('VIP ANN: [a][b] friend', $html);
    }

    public function test_html_output_is_escaped_and_text_output_is_not(): void
    {
        $data = ['name' => 'Ann <b>&</b> "Q"'];

        $this->assertSame('<p>Ann &lt;b&gt;&amp;&lt;/b&gt; &quot;Q&quot;</p>', Liquid::render('<p>{{ name }}</p>', $data));
        $this->assertSame('<p>ANN &lt;B&gt;&amp;&lt;/B&gt; &quot;Q&quot;</p>', Liquid::render('<p>{% if name %}{{ name | upcase }}{% endif %}</p>', $data));
        $this->assertSame('Hi Ann <b>&</b> "Q"', Liquid::render('Hi {{ name }}', $data, html: false));
    }

    public function test_a_list_prints_as_json_like_the_old_replacement(): void
    {
        $this->assertSame(json_encode(['email', 'café']), Liquid::render('{{ interests }}', ['interests' => ['email', 'café']], html: false));
    }

    public function test_dotted_keys_become_nested_values(): void
    {
        $this->assertSame(
            'Paris / Ann',
            Liquid::render('{{ address.city }} / {{ first-name }}', ['address.city' => 'Paris', 'first-name' => 'Ann'], html: false),
        );
    }

    public function test_a_query_string_after_a_link_variable_gets_the_right_separator(): void
    {
        $html = '<a href="{{ url }}?coupon=X">Buy</a>{% if url %}{% endif %}';

        $this->assertSame('<a href="https://shop.example.com/cart?a=1&amp;b=2&coupon=X">Buy</a>', Liquid::render($html, ['url' => 'https://shop.example.com/cart?a=1&b=2']));
        $this->assertSame('<a href="https://shop.example.com/cart?coupon=X">Buy</a>', Liquid::render($html, ['url' => 'https://shop.example.com/cart']));
    }

    public function test_contact_data_cannot_set_the_query_string_separator(): void
    {
        $html = Liquid::render('{{ url }}?a=1{% if x %}{% endif %}', ['url' => 'https://example.com', 'url__qs' => '"><script>']);

        $this->assertSame('https://example.com?a=1', $html);
    }

    public function test_an_assigned_variable_with_the_separator_suffix_is_not_printed_raw(): void
    {
        $html = Liquid::render('{% assign x__qs = name %}[{{ x__qs }}]', ['name' => '<b>']);

        $this->assertSame('[]', $html);
    }

    public function test_entities_from_the_html_editor_are_decoded_inside_liquid_code(): void
    {
        $this->assertSame('<p>many</p>', Liquid::render('<p>{% if count &gt; 1 %}many{% endif %}</p>', ['count' => 5]));
        $this->assertSame('<p>hi there</p>', Liquid::render('<p>hi {{ nickname | default: &quot;there&quot; }}</p>', []));
    }

    public function test_it_reads_no_files(): void
    {
        $this->assertStringContainsString("Unknown tag 'render'", (string) Liquid::error("{% render 'config/app.php' %}"));
        $this->assertStringContainsString("Unknown tag 'include'", (string) Liquid::error("{% include 'config/app.php' %}"));
    }

    public function test_tags_that_print_without_escaping_are_removed(): void
    {
        $this->assertStringContainsString("Unknown tag 'echo'", (string) Liquid::error('{% echo name %}'));
        $this->assertStringContainsString("Unknown tag 'liquid'", (string) Liquid::error("{% liquid\necho name %}"));
        $this->assertStringContainsString("Unknown tag 'cycle'", (string) Liquid::error("{% cycle name, 'b' %}"));
    }

    public function test_data_cannot_hold_a_php_object(): void
    {
        $object = new stdClass;
        $object->secret = 'hidden';

        $this->assertSame('[]', Liquid::render('[{{ user.secret }}]', ['user' => $object], html: false));
    }

    public function test_a_large_or_variable_range_is_refused(): void
    {
        $this->assertStringContainsString('A range must use two whole numbers', (string) Liquid::error('{% for i in (1..99999999) %}x{% endfor %}'));
        $this->assertStringContainsString('A range must use two whole numbers', (string) Liquid::error('{% for i in (1..count) %}x{% endfor %}'));
        $this->assertNull(Liquid::error('{% for i in (1..5) %}x{% endfor %}'));
    }

    public function test_a_loop_that_renders_too_much_stops(): void
    {
        $list = range(1, 400);

        $this->expectException(ResourceLimitException::class);

        Liquid::render('{% for a in list %}{% for b in list %}{{ b }}{% endfor %}{% endfor %}', ['list' => $list]);
    }

    public function test_a_render_that_runs_past_its_time_limit_stops(): void
    {
        $limits = new RenderLimits(seconds: 0.0, renderLength: 100, renderScore: 100, assignScore: 100);

        $this->expectException(ResourceLimitException::class);

        $limits->incrementRenderScore();
    }

    public function test_an_unknown_filter_is_an_error(): void
    {
        $this->assertStringContainsString('nofilter', (string) Liquid::error('{{ name | nofilter }}'));
        $this->assertStringContainsString('nofilter', (string) Liquid::error('{% assign x = name | nofilter %}'));
    }

    public function test_a_syntax_error_has_a_readable_message(): void
    {
        $this->assertStringStartsWith('The Liquid code has an error', (string) Liquid::error("<p>a</p>\n{% if vip %}VIP"));
        $this->assertNull(Liquid::error('Hi {{ 1st_order }}'));
    }

    public function test_a_render_error_is_thrown(): void
    {
        $this->expectException(LiquidException::class);

        Liquid::render('{{ 10 | divided_by: 0 }}', []);
    }

    public function test_it_lists_the_contact_variables_of_the_content(): void
    {
        $variables = Liquid::variables(
            '{% assign greeting = "Hi" %}{% capture note %}x{% endcapture %}'
            .'{{ greeting }} {{ name | upcase }} {{ address.city }} {% if vip %}{{ items.size }}{% endif %}'
            .'{% for item in items %}{{ item.title }} {{ forloop.index }}{% endfor %}'
            .'{{ unsubscribe_url }} {{ postal_address }} {{ profile.links[0] }} {{ profile }} {{ note }}',
        );

        $this->assertSame(['address.city', 'items', 'name', 'postal_address', 'profile', 'unsubscribe_url', 'vip'], $variables);
    }
}
