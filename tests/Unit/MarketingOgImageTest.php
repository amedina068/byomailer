<?php

namespace Tests\Unit;

use App\Support\MarketingOgImage;
use Tests\TestCase;

class MarketingOgImageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.snapsbrew.base_url', 'https://app.snapsbrew.com');
        config()->set('services.snapsbrew.signed_url_key', 'test-signing-key');
        config()->set('services.snapsbrew.og_template_id', 'template-id');
    }

    public function test_it_returns_null_for_unmapped_or_missing_routes(): void
    {
        $this->assertNull(MarketingOgImage::forRoute(null));
        $this->assertNull(MarketingOgImage::forRoute('dashboard'));
        $this->assertNull(MarketingOgImage::forRoute('contacts.index'));
    }

    public function test_it_maps_explicit_documentation_surfaces(): void
    {
        $this->assertSame('DOCUMENTATION', $this->variants('documentation')[0]);
        $this->assertNull(MarketingOgImage::forRoute('documentation.release-notes'));
        $this->assertNull(MarketingOgImage::forRoute('home'));
    }

    public function test_docs_guides_derive_a_humanized_headline_per_page(): void
    {
        $variants = $this->variants('documentation.guides.create-iam-user');

        $this->assertSame('DOCS · GUIDE', $variants[0]);
        $this->assertSame('Create IAM User', $variants[1]);
    }

    public function test_docs_api_resources_build_a_reference_headline(): void
    {
        $variants = $this->variants('documentation.api.newsletters');

        $this->assertSame('DOCS · REST API', $variants[0]);
        $this->assertSame('Newsletters API reference', $variants[1]);

        $indexVariants = $this->variants('documentation.api.index');
        $this->assertSame('BYOMailer REST API', $indexVariants[1]);
    }

    /**
     * Decode the variant text values embedded in a route's signed OG URL.
     *
     * @return list<string>
     */
    private function variants(string $route, ?int $limit = null): array
    {
        $url = MarketingOgImage::forRoute($route);
        $this->assertNotNull($url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $decoded = json_decode(base64_decode(strtr($query['variants'], '-_', '+/')), true);

        $texts = array_map(fn (array $v): string => $v['text'], $decoded);

        return $limit === null ? $texts : array_slice($texts, 0, $limit);
    }
}
