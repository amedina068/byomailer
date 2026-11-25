<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->completeInstanceSetup();

        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_redirected_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('home'))->assertRedirect(route('dashboard'));
    }

    public function test_marketing_pages_are_gone(): void
    {
        foreach (['/faq', '/terms', '/privacy', '/contact', '/case-studies', '/open', '/alternatives', '/compare', '/guides', '/savings-calculator', '/sitemap.xml'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
