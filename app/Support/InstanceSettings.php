<?php

namespace App\Support;

use App\Models\InstanceSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and writes the `instance_settings` table.
 *
 * The table is small, so one cache entry holds all of it. A write clears that
 * entry, and the next read loads the table again.
 */
class InstanceSettings
{
    public const CACHE_KEY = 'instance_settings';

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function set(string $key, ?string $value): void
    {
        InstanceSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    private function all(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => InstanceSetting::query()->pluck('value', 'key')->all()
        );
    }
}
