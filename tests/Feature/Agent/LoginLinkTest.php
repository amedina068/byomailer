<?php

namespace Tests\Feature\Agent;

use App\Models\User;
use App\Support\Instance\LoginLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLinkTest extends TestCase
{
    use RefreshDatabase;

    private function pathOf(string $url): string
    {
        return parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
    }

    public function test_the_link_starts_with_the_app_url_and_signs_the_user_in(): void
    {
        $user = User::factory()->owner()->create();
        $url = LoginLink::create($user);

        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/login/link/', $url);
        $this->get($this->pathOf($url))->assertRedirect(config('fortify.home'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_changed_signature_or_user_is_refused(): void
    {
        $user = User::factory()->owner()->create();
        $other = User::factory()->create();
        $path = $this->pathOf(LoginLink::create($user));

        $this->get(str_replace('signature=', 'signature=0', $path))->assertRedirect('/login');
        $this->get(str_replace("/login/link/{$user->id}/", "/login/link/{$other->id}/", $path))->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->owner()->create();
        $path = $this->pathOf(LoginLink::create($user));

        $this->travel(LoginLink::MINUTES + 1)->minutes();

        $this->get($path)->assertRedirect('/login')->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        $user = User::factory()->owner()->create(['deactivated_at' => now()]);

        $this->get($this->pathOf(LoginLink::create($user)))->assertRedirect('/login');
        $this->assertGuest();
    }
}
