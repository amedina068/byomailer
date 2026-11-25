<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `composer setup` and `npm run build` write the SSR bundle to
 * `bootstrap/ssr/ssr.js`. The tests must pass while that file exists. With SSR
 * on, Inertia sends each page to the SSR server, and `Http::preventStrayRequests()`
 * stops that request, so each page test fails.
 */
class SsrBundleInTestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ssr_is_off_in_the_test_environment(): void
    {
        $this->assertFalse(config('inertia.ssr.enabled'));
    }

    public function test_a_page_renders_while_an_ssr_bundle_exists(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'ssr-bundle-');
        config(['inertia.ssr.bundle' => $bundle]);
        $this->completeInstanceSetup();

        try {
            $response = $this->get('/login');
        } finally {
            unlink($bundle);
        }

        $response->assertOk();
    }
}
