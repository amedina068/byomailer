<?php

namespace Tests\Unit;

use App\Support\SnapsbrewOgImage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SnapsbrewOgImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.snapsbrew.base_url', 'https://app.snapsbrew.com');
        config()->set('services.snapsbrew.signed_url_key', 'test-signing-key');
        config()->set('services.snapsbrew.og_template_id', 'b3b0aa20-3bae-4792-bd2d-2479e1782bad');
    }

    public function test_it_builds_a_signed_url_matching_the_snapsbrew_spec(): void
    {
        $url = SnapsbrewOgImage::url('CASE STUDY', 'Big news');

        $this->assertNotNull($url);

        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        $this->assertSame('app.snapsbrew.com', $parts['host']);
        $this->assertSame('/signed-url/image.jpg', $parts['path']);
        $this->assertSame('b3b0aa20-3bae-4792-bd2d-2479e1782bad', $query['template_id']);
        $this->assertArrayHasKey('variants', $query);
        $this->assertArrayHasKey('signature', $query);

        $expectedVariants = rtrim(strtr(base64_encode(json_encode([
            ['layer' => 'eyebrow', 'text' => 'CASE STUDY'],
            ['layer' => 'headline', 'text' => 'Big news'],
        ])), '+/', '-_'), '=');

        $this->assertSame($expectedVariants, $query['variants']);
    }

    public function test_signature_is_hmac_of_the_alphabetically_sorted_query(): void
    {
        $url = SnapsbrewOgImage::url('A', 'B');

        $parts = parse_url($url);
        parse_str($parts['query'], $query);

        $signature = $query['signature'];
        unset($query['signature']);
        ksort($query);

        $expected = hash_hmac('sha256', http_build_query($query), 'test-signing-key');

        $this->assertSame($expected, $signature);
    }

    public function test_variants_are_base64url_encoded_without_unsafe_characters(): void
    {
        $url = SnapsbrewOgImage::url('DOCS · REST API', 'Newsletters API reference');

        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $query['variants']);
    }

    public function test_the_default_base_url_is_the_snapsbrew_app_host(): void
    {
        $this->refreshApplication();

        $this->assertSame('https://app.snapsbrew.com', config('services.snapsbrew.base_url'));
    }

    public function test_it_uses_the_configured_base_url(): void
    {
        config()->set('services.snapsbrew.base_url', 'https://snapsbrew.example/');

        $url = SnapsbrewOgImage::url('A', 'B');

        $this->assertStringStartsWith('https://snapsbrew.example/signed-url/image.jpg?', $url);
    }

    #[DataProvider('missingConfigProvider')]
    public function test_it_returns_null_when_integration_is_not_configured(?string $key, ?string $template): void
    {
        config()->set('services.snapsbrew.signed_url_key', $key);
        config()->set('services.snapsbrew.og_template_id', $template);

        $this->assertNull(SnapsbrewOgImage::url('A', 'B'));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function missingConfigProvider(): array
    {
        return [
            'no key' => [null, 'template-id'],
            'no template' => ['key', null],
            'neither' => [null, null],
        ];
    }
}
