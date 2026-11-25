<?php

namespace App\Services;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeoIpService
{
    private ?Reader $reader = null;

    /**
     * @return array{timezone: string|null, country: string|null, city: string|null}
     */
    public function lookup(string $ipAddress): array
    {
        $empty = ['timezone' => null, 'country' => null, 'city' => null];

        if ($this->isPrivateIp($ipAddress)) {
            return $empty;
        }

        $reader = $this->getReader();

        if (! $reader) {
            return $empty;
        }

        try {
            $record = $reader->city($ipAddress);

            return [
                'timezone' => $record->location->timeZone,
                'country' => $record->country->isoCode,
                'city' => $record->city->name,
            ];
        } catch (AddressNotFoundException) {
            return $empty;
        } catch (Throwable $e) {
            Log::warning('GeoIpService lookup failed', [
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
            ]);

            return $empty;
        }
    }

    private function isPrivateIp(string $ipAddress): bool
    {
        return ! filter_var(
            $ipAddress,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function getReader(): ?Reader
    {
        if ($this->reader) {
            return $this->reader;
        }

        $path = config('services.maxmind.database_path');

        if (! $path || ! file_exists($path)) {
            return null;
        }

        try {
            $this->reader = new Reader($path);

            return $this->reader;
        } catch (Throwable $e) {
            Log::warning('GeoIpService: unable to open MaxMind database', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
