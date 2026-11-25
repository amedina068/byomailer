<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->completeInstanceSetup();
    }

    public function test_the_register_page_is_not_found_when_registration_is_disabled(): void
    {
        config(['app.registration_enabled' => false]);

        $this->get('/register')->assertNotFound();
    }

    public function test_a_visitor_cannot_register_when_registration_is_disabled(): void
    {
        config(['app.registration_enabled' => false]);

        $this->post('/register', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'timezone' => 'UTC',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_the_login_page_hides_the_register_link_when_registration_is_disabled(): void
    {
        config(['app.registration_enabled' => false]);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('canRegister', false));
    }

    public function test_the_login_page_shows_the_register_link_when_registration_is_enabled(): void
    {
        config(['app.registration_enabled' => true]);

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('canRegister', true));
    }

    public function test_a_visitor_can_register_when_registration_is_enabled(): void
    {
        config(['app.registration_enabled' => true]);

        $this->get('/register')->assertOk();

        $this->post('/register', [
            'name' => 'Open User',
            'email' => 'open@example.com',
            'timezone' => 'UTC',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }
}
