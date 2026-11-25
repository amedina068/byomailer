<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_pages_cannot_be_framed_by_another_site(): void
    {
        User::factory()->create();

        $this->assertSecurityHeaders($this->get(route('login')));
    }

    public function test_dashboard_pages_send_the_security_headers(): void
    {
        $user = User::factory()->create();

        $this->assertSecurityHeaders($this->actingAs($user)->get(route('contacts.index')));
    }

    public function test_api_responses_send_the_security_headers(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->assertSecurityHeaders(
            $this->withToken($token)->getJson(route('api.v1.tags.index'))->assertOk()
        );
    }

    public function test_a_header_that_a_route_sets_is_kept(): void
    {
        Route::get('/__security-headers-own', fn () => response('ok')->header('X-Frame-Options', 'SAMEORIGIN'));

        $this->get('/__security-headers-own')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    private function assertSecurityHeaders(TestResponse $response): void
    {
        $response
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }
}
