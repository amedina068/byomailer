<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\MailList;
use App\Models\Segment;
use Illuminate\Database\Seeder;

class SegmentSeeder extends Seeder
{
    /**
     * Seed a varied set of demo segments for the primary demo client.
     */
    public function seedDemoSegments(Client $client, int $customersListId): void
    {
        Segment::factory()->for($client)->createMany([
            [
                'name' => 'Active Pro Customers',
                'description' => 'Pro plan contacts who are still active.',
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'attributes',
                        'mode' => 'all',
                        'values' => [
                            ['key' => 'plan', 'value' => 'pro'],
                            ['key' => 'status', 'value' => 'active'],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'VIP & Premium',
                'description' => 'Anyone tagged vip or premium.',
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'tags',
                        'mode' => 'any',
                        'values' => ['vip', 'premium'],
                    ],
                ],
            ],
            [
                'name' => 'Customers — engaged last 30 days',
                'description' => 'Customers list members active in the last month.',
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'lists',
                        'mode' => 'any',
                        'values' => [$customersListId],
                    ],
                    [
                        'connector' => 'and',
                        'category' => 'date',
                        'operator' => 'in_last',
                        'values' => ['days' => 30],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Seed a single list-backed segment for a secondary demo client.
     */
    public function seedClientSegment(Client $client, MailList $customersList): Segment
    {
        return Segment::factory()->for($client)->create([
            'name' => $client->name.' Customers',
            'description' => 'Members of the Customers list.',
            'filters' => [
                [
                    'connector' => 'and',
                    'category' => 'lists',
                    'mode' => 'any',
                    'values' => [$customersList->id],
                ],
            ],
        ]);
    }
}
