<?php

namespace Tests\Unit;

use App\Services\GeoIpService;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use GeoIp2\Model\City;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class GeoIpServiceTest extends TestCase
{
    public function test_returns_empty_result_for_private_ip(): void
    {
        $service = new GeoIpService;

        $result = $service->lookup('192.168.1.1');

        $this->assertNull($result['timezone']);
        $this->assertNull($result['country']);
        $this->assertNull($result['city']);
    }

    public function test_returns_empty_result_for_loopback_ip(): void
    {
        $service = new GeoIpService;

        $result = $service->lookup('127.0.0.1');

        $this->assertNull($result['timezone']);
        $this->assertNull($result['country']);
        $this->assertNull($result['city']);
    }

    public function test_returns_empty_result_when_database_missing(): void
    {
        config(['services.maxmind.database_path' => '/nonexistent/path.mmdb']);

        $service = new GeoIpService;

        $result = $service->lookup('8.8.8.8');

        $this->assertNull($result['timezone']);
        $this->assertNull($result['country']);
        $this->assertNull($result['city']);
    }

    public function test_returns_empty_result_when_address_not_found(): void
    {
        $mockReader = Mockery::mock(Reader::class);
        $mockReader->shouldReceive('city')
            ->with('8.8.8.8')
            ->andThrow(new AddressNotFoundException('Not found'));

        $service = $this->createServiceWithReader($mockReader);

        $result = $service->lookup('8.8.8.8');

        $this->assertNull($result['timezone']);
        $this->assertNull($result['country']);
        $this->assertNull($result['city']);
    }

    public function test_returns_geo_data_from_valid_ip(): void
    {
        $cityRecord = new City([
            'city' => ['names' => ['en' => 'New York']],
            'country' => ['iso_code' => 'US', 'names' => ['en' => 'United States']],
            'location' => ['time_zone' => 'America/New_York'],
        ], ['en']);

        $mockReader = Mockery::mock(Reader::class);
        $mockReader->shouldReceive('city')
            ->with('8.8.8.8')
            ->andReturn($cityRecord);

        $service = $this->createServiceWithReader($mockReader);

        $result = $service->lookup('8.8.8.8');

        $this->assertSame('America/New_York', $result['timezone']);
        $this->assertSame('US', $result['country']);
        $this->assertSame('New York', $result['city']);
    }

    private function createServiceWithReader(Reader $reader): GeoIpService
    {
        $service = new GeoIpService;

        $reflection = new ReflectionClass($service);
        $property = $reflection->getProperty('reader');
        $property->setValue($service, $reader);

        return $service;
    }
}
