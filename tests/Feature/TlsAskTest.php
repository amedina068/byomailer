<?php

namespace Tests\Feature;

use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\TrackingDomainVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TlsAskTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-ask-token';

    public function test_returns_200_for_verified_tracking_domain(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $this->get(route('tls.ask', ['domain' => 'track.example.com', 'token' => self::TOKEN]))
            ->assertOk();
    }

    public function test_returns_404_for_unverified_tracking_domain(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => null,
        ]);

        $this->get(route('tls.ask', ['domain' => 'track.example.com', 'token' => self::TOKEN]))
            ->assertNotFound();
    }

    public function test_returns_404_for_unknown_domain(): void
    {
        $this->get(route('tls.ask', ['domain' => 'attacker.example.com', 'token' => self::TOKEN]))
            ->assertNotFound();
    }

    public function test_returns_404_when_domain_param_missing(): void
    {
        $this->get(route('tls.ask', ['token' => self::TOKEN]))->assertNotFound();
    }

    public function test_lowercases_input_domain(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $this->get(route('tls.ask', ['domain' => 'TRACK.example.com', 'token' => self::TOKEN]))
            ->assertOk();
    }

    public function test_returns_200_when_tls_allow_cache_flag_set_even_if_unverified(): void
    {
        Cache::put(TrackingDomainVerifier::tlsAllowCacheKey('track.example.com'), true, 60);

        $this->get(route('tls.ask', ['domain' => 'track.example.com', 'token' => self::TOKEN]))
            ->assertOk();
    }

    public function test_cache_flag_lookup_is_case_insensitive(): void
    {
        Cache::put(TrackingDomainVerifier::tlsAllowCacheKey('track.example.com'), true, 60);

        $this->get(route('tls.ask', ['domain' => 'TRACK.example.com', 'token' => self::TOKEN]))
            ->assertOk();
    }

    public function test_works_unauthenticated(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $this->get(route('tls.ask', ['domain' => 'track.example.com', 'token' => self::TOKEN]))
            ->assertOk();
    }

    public function test_returns_404_when_token_missing(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $this->get(route('tls.ask', ['domain' => 'track.example.com']))
            ->assertNotFound();
    }

    public function test_returns_404_when_token_invalid(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $this->get(route('tls.ask', ['domain' => 'track.example.com', 'token' => 'wrong']))
            ->assertNotFound();
    }

    public function test_returns_404_when_server_ask_token_unset(): void
    {
        config(['services.tracking_proxy.ask_token' => null]);

        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->withConfigurationSet()->create([
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now(),
        ]);

        $this->get(route('tls.ask', ['domain' => 'track.example.com', 'token' => '']))
            ->assertNotFound();
    }
}
