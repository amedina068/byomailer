<?php

namespace Tests\Feature\Api;

use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegacyRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_unversioned_path_redirects_308_to_v1(): void
    {
        $response = $this->getJson('/api/newsletters');

        $response->assertStatus(308);
        $this->assertStringEndsWith('/api/v1/newsletters', $response->headers->get('Location'));
    }

    public function test_redirect_preserves_query_string(): void
    {
        $response = $this->getJson('/api/newsletters?status=draft&search=foo');

        $response->assertStatus(308);
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/api/v1/newsletters?', $location);
        $this->assertStringContainsString('status=draft', $location);
        $this->assertStringContainsString('search=foo', $location);
    }

    public function test_redirect_works_for_post(): void
    {
        $response = $this->postJson('/api/newsletters', ['title' => 'Hi']);

        $response->assertStatus(308);
        $this->assertStringEndsWith('/api/v1/newsletters', $response->headers->get('Location'));
    }

    public function test_redirect_does_not_loop_on_v1_paths(): void
    {
        // hitting an unknown v1 path should 401 (auth required) — not redirect.
        $response = $this->getJson('/api/v1/nonexistent');

        $response->assertNotFound();
    }

    public function test_unversioned_works_end_to_end_when_followed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        Newsletter::factory()->for($user)->create(['title' => 'Hello']);

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->get('/api/newsletters');

        $response->assertStatus(308);

        $followed = $this->withHeaders(['Accept' => 'application/json'])
            ->get($response->headers->get('Location'));

        $followed->assertOk()->assertJsonPath('data.0.title', 'Hello');
    }
}
