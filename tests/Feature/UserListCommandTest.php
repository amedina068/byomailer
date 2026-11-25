<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserListCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_every_user(): void
    {
        $owner = User::factory()->owner()->create([
            'name' => 'Olive Owner',
            'email' => 'owner@example.com',
            'client_id' => Client::factory()->create(['name' => 'Olive Studio']),
        ]);
        $member = User::factory()->create([
            'name' => 'Max Member',
            'email' => 'member@example.com',
            'client_id' => Client::factory()->create(['name' => 'Max Studio']),
            'deactivated_at' => now(),
        ]);

        $this->artisan('byomailer:user:list')
            ->expectsTable(
                ['ID', 'Name', 'Email', 'Account', 'Owner', 'Status', 'Created'],
                [
                    [$owner->id, 'Olive Owner', 'owner@example.com', 'Olive Studio', 'yes', 'active', $owner->created_at->toDateString()],
                    [$member->id, 'Max Member', 'member@example.com', 'Max Studio', 'no', 'deactivated', $member->created_at->toDateString()],
                ],
            )
            ->assertSuccessful();
    }

    public function test_it_filters_by_name_or_email(): void
    {
        User::factory()->create(['name' => 'Olive Owner', 'email' => 'owner@example.com']);
        User::factory()->create(['name' => 'Max Member', 'email' => 'max@other.test']);

        $this->artisan('byomailer:user:list', ['--search' => 'olive'])
            ->expectsOutputToContain('owner@example.com')
            ->doesntExpectOutputToContain('max@other.test')
            ->assertSuccessful();

        $this->artisan('byomailer:user:list', ['--search' => 'other.test'])
            ->expectsOutputToContain('max@other.test')
            ->doesntExpectOutputToContain('owner@example.com')
            ->assertSuccessful();
    }

    public function test_it_says_when_no_user_matches(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('byomailer:user:list', ['--search' => 'nobody'])
            ->expectsOutputToContain('No user matches.')
            ->assertSuccessful();
    }
}
