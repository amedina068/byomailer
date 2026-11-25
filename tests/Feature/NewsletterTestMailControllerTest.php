<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\ContactAttribute;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\TestMail;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class NewsletterTestMailControllerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_it_sends_test_email_to_authenticated_user_using_their_ses_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'name' => 'Test Owner',
        ]);

        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLE123456789',
            'secret_key' => 'example-secret-key-1234567890',
            'region' => 'us-east-1',
        ]);

        $newsletter = Newsletter::factory()->for($user)->create();

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')
            ->once()
            ->withArgs(function (array $config) use ($user): bool {
                return ($config['name'] ?? null) === 'ses-user-'.$user->id
                    && ($config['transport'] ?? null) === 'ses'
                    && ($config['key'] ?? null) === 'AKIAEXAMPLE123456789'
                    && ($config['secret'] ?? null) === 'example-secret-key-1234567890'
                    && ($config['region'] ?? null) === 'us-east-1';
            })
            ->andReturn($mailer);

        $mailer->shouldReceive('send')
            ->once()
            ->withArgs(fn (NewsletterMail $mail): bool => true)
            ->andReturnNull();

        $this->app->instance(MailManager::class, $mailManager);

        $response = $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('test_mails', [
            'user_id' => $user->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
        ]);

        $this->assertDatabaseHas('contact_mails', [
            'mailable_type' => TestMail::class,
            'subject' => '[Test] '.$newsletter->message->subject,
        ]);

        $this->assertDatabaseHas('contacts', [
            'email' => 'owner@example.com',
            'client_id' => $user->client_id,
        ]);
    }

    public function test_it_replaces_contact_variables_in_body_subject_and_preview_text(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'name' => 'Ada Lovelace',
        ]);
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->update([
            'subject' => 'Hi {{ name }}',
            'preview_text' => 'For {{ email }}',
            'html_content' => '<p>Hello {{ name }} at {{ email }}</p>',
            'text_content' => 'Hello {{ name }} at {{ email }}',
        ]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $capturedMail = null;
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$capturedMail): void {
                $capturedMail = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $this->assertNotNull($capturedMail);
        $this->assertSame('[Test] Hi Ada Lovelace', $capturedMail->envelope()->subject);

        $rendered = $capturedMail->render();
        $this->assertStringNotContainsString('{{ name }}', $rendered);
        $this->assertStringNotContainsString('{{ email }}', $rendered);
        $this->assertStringContainsString('Ada Lovelace', $rendered);
        $this->assertStringContainsString('owner@example.com', $rendered);
    }

    public function test_it_fills_blank_variables_from_global_attribute_defaults(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'name' => 'Ada Lovelace',
        ]);
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        ContactAttribute::factory()->withDefault('Acme Corp')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->update([
            'subject' => 'From {{ company }}',
            'html_content' => '<p>Greetings from {{ company }}</p>',
            'text_content' => 'Greetings from {{ company }}',
        ]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $capturedMail = null;
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$capturedMail): void {
                $capturedMail = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $this->assertNotNull($capturedMail);

        $rendered = $capturedMail->render();
        $this->assertStringNotContainsString('{{ company }}', $rendered);
        $this->assertStringContainsString('Acme Corp', $rendered);
    }

    public function test_it_sends_test_email_using_the_message_email_identity(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $savedIdentity = $newsletter->message->emailIdentity;

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $capturedMail = null;
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$capturedMail): void {
                $capturedMail = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $this->assertNotNull($capturedMail);
        $this->assertSame($savedIdentity->email, $capturedMail->envelope()->from->address);
    }

    public function test_it_sends_test_email_using_newsletter_message_identity_when_no_request_identity(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $savedIdentity = $newsletter->message->emailIdentity;

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $mailer->shouldReceive('send')
            ->once()
            ->withArgs(function (NewsletterMail $mail) use ($savedIdentity): bool {
                return $mail->envelope()->from->address === $savedIdentity->email;
            })
            ->andReturnNull();

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));
    }

    public function test_it_sends_test_email_using_the_selected_request_identity(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $selectedIdentity = EmailIdentity::factory()->for($user)->create([
            'email' => 'picked@example.com',
        ]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $capturedMail = null;
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$capturedMail): void {
                $capturedMail = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter), [
            'email_identity_id' => $selectedIdentity->id,
        ]);

        $this->assertNotNull($capturedMail);
        $this->assertSame('picked@example.com', $capturedMail->envelope()->from->address);
    }

    public function test_it_returns_error_when_selected_identity_belongs_to_another_user(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $foreignIdentity = EmailIdentity::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter), [
            'email_identity_id' => $foreignIdentity->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_subject_is_prefixed_with_test_label(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['subject' => 'Monthly update']);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $mailer->shouldReceive('send')
            ->once()
            ->withArgs(function (NewsletterMail $mail): bool {
                return $mail->envelope()->subject === '[Test] Monthly update';
            })
            ->andReturnNull();

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));
    }

    public function test_it_returns_error_when_no_email_identity_is_available(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => null]);

        $response = $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_a_failed_test_mail_reaches_the_page_as_the_shared_flash_error(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => null]);

        $this->actingAs($user)
            ->from(route('newsletters.schedule.show', $newsletter))
            ->followingRedirects()
            ->post(route('newsletters.test-mail', $newsletter))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.error', 'Please select a sender before sending a test email.')
                ->where('flash.success', null)
            );
    }

    public function test_it_returns_404_when_newsletter_belongs_to_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        EmailCredential::factory()->for($otherUser)->create();

        $newsletter = Newsletter::factory()->for($owner)->create();

        $this->actingAs($otherUser)
            ->post(route('newsletters.test-mail', $newsletter))
            ->assertNotFound();
    }

    public function test_it_applies_utm_parameters_to_newsletter_links(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'html_content' => '<a href="https://example.com">Click here</a>',
            'subject' => 'Monthly Update',
        ]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $capturedMail = null;
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$capturedMail): void {
                $capturedMail = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $this->assertNotNull($capturedMail);
        $rendered = $capturedMail->render();
        $this->assertStringContainsString('utm_medium=mail', $rendered);
        $this->assertStringContainsString('utm_source=monthly-update', $rendered);
        $this->assertStringContainsString('utm_campaign=newsletter', $rendered);
    }

    public function test_it_does_not_append_utm_params_to_signed_preferences_and_unsubscribe_links(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'html_content' => '<p><a href="https://example.com/article">Read</a> '
                .'<a href="{{ preferences_url }}">Preferences</a> '
                .'<a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            'subject' => 'Monthly Update',
        ]);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $capturedMail = null;
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$capturedMail): void {
                $capturedMail = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $this->assertNotNull($capturedMail);
        $rendered = $capturedMail->render();

        $this->assertStringContainsString('utm_medium=mail', $rendered);

        $this->assertMatchesRegularExpression(
            '#href="[^"]*/preferences/[^"]*"#',
            $rendered,
        );
        preg_match('#href="([^"]*/preferences/[^"]*)"#', $rendered, $prefMatch);
        $this->assertArrayHasKey(1, $prefMatch);
        $this->assertStringNotContainsString('utm_', $prefMatch[1]);

        preg_match('#href="([^"]*/unsubscribe/[^"]*)"#', $rendered, $unsubMatch);
        $this->assertArrayHasKey(1, $unsubMatch);
        $this->assertStringNotContainsString('utm_', $unsubMatch[1]);
    }

    public function test_it_requires_authentication(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->post(route('newsletters.test-mail', $newsletter))
            ->assertRedirect(route('login'));
    }
}
