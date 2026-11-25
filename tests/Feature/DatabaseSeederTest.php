<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MailList;
use App\Models\Segment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SegmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->app->make(DatabaseSeeder::class)->run();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('production', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'demo@example.com']);
    }

    public function test_demo_segments_are_seeded_with_non_empty_filters(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->for($client)->create();
        $customers = MailList::factory()->for($user)->create(['name' => 'Customers']);

        app(SegmentSeeder::class)->seedDemoSegments($client, $customers->id);

        $segments = Segment::withoutGlobalScopes()->where('client_id', $client->id)->get();

        $this->assertCount(3, $segments);
        $this->assertContains('Active Pro Customers', $segments->pluck('name')->all());
        $this->assertTrue(
            $segments->every(fn (Segment $segment): bool => is_array($segment->filters) && $segment->filters !== []),
            'Every seeded segment should carry a non-empty filters array.',
        );
    }

    public function test_client_segment_is_scoped_and_targets_the_customers_list(): void
    {
        $client = Client::factory()->create(['name' => 'Acme Co']);
        $user = User::factory()->for($client)->create();
        $customers = MailList::factory()->for($user)->create(['name' => 'Customers']);

        $segment = app(SegmentSeeder::class)->seedClientSegment($client, $customers);

        $this->assertSame($client->id, $segment->client_id);
        $this->assertSame('Acme Co Customers', $segment->name);
        $this->assertSame([
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$customers->id],
            ],
        ], $segment->filters);
    }
}
