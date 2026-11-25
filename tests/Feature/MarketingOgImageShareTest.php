<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MarketingOgImageShareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.snapsbrew.base_url', 'https://app.snapsbrew.com');
        config()->set('services.snapsbrew.signed_url_key', 'test-signing-key');
        config()->set('services.snapsbrew.og_template_id', 'template-id');
    }

    public function test_documentation_guide_shares_a_signed_og_image(): void
    {
        $this->get(route('documentation.guides.getting-started'))->assertInertia(
            fn (Assert $page) => $page->where(
                'ogImage',
                fn (?string $url): bool => is_string($url) && str_contains($url, '/signed-url/image.jpg'),
            ),
        );
    }

    public function test_unconfigured_integration_shares_null(): void
    {
        config()->set('services.snapsbrew.signed_url_key', null);

        $this->get(route('documentation.guides.getting-started'))->assertInertia(
            fn (Assert $page) => $page->where('ogImage', null),
        );
    }
}
