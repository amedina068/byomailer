<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class E2eSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_owner_the_tag_and_the_contact_of_the_e2e_tests(): void
    {
        $this->seed(E2eSeeder::class);

        $user = User::query()->where('email', E2eSeeder::EMAIL)->sole();

        $this->assertTrue((bool) $user->is_owner);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(E2eSeeder::PASSWORD, $user->password));

        $this->assertSame(1, Tag::withoutGlobalScopes()
            ->where('client_id', $user->client_id)
            ->where('name', E2eSeeder::TAG_NAME)
            ->count());
        $this->assertSame(1, Contact::withoutGlobalScopes()
            ->where('client_id', $user->client_id)
            ->where('email', E2eSeeder::CONTACT_EMAIL)
            ->count());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->app->make(E2eSeeder::class)->run();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('production', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => E2eSeeder::EMAIL]);
    }
}
