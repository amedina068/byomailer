<?php

namespace Tests\Feature\Auth;

use App\Jobs\SyncContactToByomailerList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->completeInstanceSetup();
        config(['app.registration_enabled' => true]);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'timezone' => 'UTC',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertNotNull($user->client_id);
    }

    public function test_registration_dispatches_byomailer_list_sync_with_free_tag(): void
    {
        config([
            'services.byomailer.api_url' => 'https://mail.example.com/api',
            'services.byomailer.api_token' => 'token',
            'services.byomailer.list_id' => '1',
        ]);
        Queue::fake();

        $this->post('/register', [
            'name' => 'Sync User',
            'email' => 'sync@example.com',
            'timezone' => 'UTC',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Queue::assertPushed(SyncContactToByomailerList::class, function (SyncContactToByomailerList $job) {
            return $job->email === 'sync@example.com'
                && $job->name === 'Sync User'
                && $job->tags === ['free']
                && $job->extraAttributes === [];
        });
    }

    public function test_registration_does_not_queue_the_list_sync_when_it_is_not_configured(): void
    {
        config(['services.byomailer.api_token' => null]);
        Queue::fake();

        $this->post('/register', [
            'name' => 'Plain User',
            'email' => 'plain@example.com',
            'timezone' => 'UTC',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'plain@example.com']);
        Queue::assertNotPushed(SyncContactToByomailerList::class);
    }
}
