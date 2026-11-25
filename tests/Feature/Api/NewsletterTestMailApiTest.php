<?php

namespace Tests\Feature\Api;

use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\UserMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NewsletterTestMailApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_send_test_mail(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $factory = Mockery::mock(UserMailerFactory::class);
        $factory->shouldReceive('mailerFor')->andReturn(new PendingMail(Mail::mailer()));
        $this->app->instance(UserMailerFactory::class, $factory);

        $newsletter = Newsletter::factory()->for($user)->create();

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/test-mail");

        $response->assertOk()
            ->assertJsonPath('sent_to', $user->email);

        Mail::assertSent(NewsletterMail::class);
    }

    public function test_test_mail_requires_email_identity(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $newsletter = Newsletter::factory()->for($user)->create();
        // wipe message identity to simulate missing sender
        $newsletter->message->update(['email_identity_id' => null]);

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/test-mail");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email_identity_id');
    }

    public function test_test_mail_returns_422_when_the_send_fails(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $factory = Mockery::mock(UserMailerFactory::class);
        $factory->shouldReceive('mailerFor')->andThrow(new RuntimeException('SES rejected the message.'));
        $this->app->instance(UserMailerFactory::class, $factory);

        $newsletter = Newsletter::factory()->for($user)->create();

        $this->postJson("/api/v1/newsletters/{$newsletter->id}/test-mail")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['send' => 'Failed to send test email. Please check your SES credentials.']);
    }

    public function test_test_mail_returns_404_for_missing_newsletter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/api/v1/newsletters/9999/test-mail');

        $response->assertNotFound();
    }

    public function test_test_mail_does_not_leak_across_clients(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $other = User::factory()->create();
        $newsletter = Newsletter::factory()->for($other)->create();

        $response = $this->postJson("/api/v1/newsletters/{$newsletter->id}/test-mail");

        $response->assertNotFound();
    }

    public function test_test_mail_unauthenticated_returns_401(): void
    {
        $response = $this->postJson('/api/v1/newsletters/1/test-mail');

        $response->assertUnauthorized();
    }
}
