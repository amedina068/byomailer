<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\ContactAttribute;
use App\Models\EmailCredential;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class SequenceStepTestMailControllerTest extends TestCase
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

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

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

        $response = $this->actingAs($user)->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }

    public function test_it_sends_using_newsletter_mail_with_placeholder_template_data(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $step->message()->update([
            'html_content' => '<p>Hello {{ first_name }}, unsubscribe at {{ unsubscribe_url }}</p>',
            'text_content' => 'Hello {{ first_name }}',
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

        $this->actingAs($user)->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $this->assertNotNull($capturedMail);
        $rendered = $capturedMail->render();

        $this->assertStringNotContainsString('{{ first_name }}', $rendered);
        $this->assertStringNotContainsString('{{ unsubscribe_url }}', $rendered);
        $this->assertStringContainsString('/unsubscribe/', $rendered);
    }

    public function test_subject_is_prefixed_with_test_label(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update(['subject' => 'Welcome aboard']);

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->once()->andReturn($mailer);

        $mailer->shouldReceive('send')
            ->once()
            ->withArgs(function (NewsletterMail $mail): bool {
                return $mail->envelope()->subject === '[Test] Welcome aboard';
            })
            ->andReturnNull();

        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));
    }

    public function test_it_replaces_contact_variables_in_body_subject_and_preview_text(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'name' => 'Ada Lovelace',
        ]);
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update([
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

        $this->actingAs($user)->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

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

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update([
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

        $this->actingAs($user)->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $this->assertNotNull($capturedMail);

        $rendered = $capturedMail->render();
        $this->assertStringNotContainsString('{{ company }}', $rendered);
        $this->assertStringContainsString('Acme Corp', $rendered);
    }

    public function test_it_returns_404_when_sequence_belongs_to_another_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        EmailCredential::factory()->for($otherUser)->create();

        $sequence = Sequence::factory()->for($owner)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $this->actingAs($otherUser)
            ->post(route('sequences.steps.test-mail', [
                'sequence' => $sequence,
                'sequenceStep' => $step,
            ]))
            ->assertNotFound();
    }

    public function test_it_applies_utm_parameters_to_sequence_step_links(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create(['name' => 'My Sequence']);
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update([
            'html_content' => '<a href="https://example.com">Click here</a>',
            'subject' => 'Welcome Email',
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

        $this->actingAs($user)->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $this->assertNotNull($capturedMail);
        $rendered = $capturedMail->render();
        $this->assertStringContainsString('utm_medium=mail', $rendered);
        $this->assertStringContainsString('utm_source=welcome-email', $rendered);
        $this->assertStringContainsString('utm_campaign=my-sequence', $rendered);
    }

    public function test_a_failed_test_mail_reaches_the_page_as_the_shared_flash_error(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('SES rejected the message.'));
        $this->app->instance(MailManager::class, $mailManager);

        $stepUrl = route('sequences.steps.show', ['sequence' => $sequence, 'sequenceStep' => $step]);

        $this->actingAs($user)
            ->from($stepUrl)
            ->followingRedirects()
            ->post(route('sequences.steps.test-mail', [
                'sequence' => $sequence,
                'sequenceStep' => $step,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.error', 'Failed to send test email. Please check your SES credentials.')
                ->where('flash.success', null)
            );
    }

    public function test_it_requires_authentication(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $this->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]))->assertRedirect(route('login'));
    }
}
