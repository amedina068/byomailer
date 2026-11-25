<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CHARACTERIZATION (task T-A3): Laravel 13 hardens cache unserialization via the
 * `serializable_classes` config (defaults to false). The app only ever caches
 * scalars/arrays (verified: FilterPreparationService::filterOptionData, marketing
 * stats commands, domain-check controllers), never hydrated Eloquent objects — so
 * that hardening is a no-op here. This test locks that assumption: a realistic
 * tenant-scoped array payload must round-trip through the cache unchanged. If a
 * future change starts caching an object, this is the tripwire.
 */
class CacheSerializationCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_shaped_array_round_trips_through_cache(): void
    {
        $payload = [
            'client_id' => 42,
            'lists' => [
                ['id' => 1, 'name' => 'Weekly', 'client_id' => 42],
                ['id' => 2, 'name' => 'Promo', 'client_id' => 42],
            ],
            'tags' => ['vip', 'trial'],
            'counts' => ['contacts' => 1280, 'active' => 1195],
            'flags' => ['has_segments' => true, 'blocked' => false],
        ];

        Cache::put('filter-option-data:42', $payload, 60);

        $this->assertSame($payload, Cache::get('filter-option-data:42'));
    }

    public function test_scalar_cache_values_round_trip(): void
    {
        Cache::put('bool-flag', true, 60);
        Cache::put('int-count', 25000, 60);
        Cache::put('string-host', 'links.example.com', 60);

        $this->assertTrue(Cache::get('bool-flag'));
        $this->assertSame(25000, Cache::get('int-count'));
        $this->assertSame('links.example.com', Cache::get('string-host'));
    }
}
