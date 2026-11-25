<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class OptionalScheduleTest extends TestCase
{
    private function geoIpEvent(): Event
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'geoip:download'));

        $this->assertNotNull($event);

        return $event;
    }

    public function test_the_geoip_download_does_not_run_without_a_license_key(): void
    {
        config(['services.maxmind.license_key' => null]);

        $this->assertFalse($this->geoIpEvent()->filtersPass($this->app));
    }

    public function test_the_geoip_download_runs_with_a_license_key(): void
    {
        config(['services.maxmind.license_key' => 'license-key']);

        $this->assertTrue($this->geoIpEvent()->filtersPass($this->app));
    }
}
