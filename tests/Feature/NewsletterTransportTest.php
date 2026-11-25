<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterMailExport;
use App\Services\NewsletterTransport;
use App\Services\SesBulkEmailService;
use App\Services\UserMailerFactory;
use App\Support\ContentSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class NewsletterTransportTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_create_template_calls_ses_when_ses_api_enabled(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldReceive('createNewsletterTemplate')
            ->once()
            ->with($newsletter, $user, $newsletter->message)
            ->andReturn('newsletter-42-uuid');
        $this->app->instance(SesBulkEmailService::class, $ses);

        $result = app(NewsletterTransport::class)->createTemplate($newsletter, $user, $newsletter->message);

        $this->assertSame('newsletter-42-uuid', $result);
    }

    public function test_create_template_returns_null_when_ses_api_disabled(): void
    {
        config(['mail.use_ses_api' => false]);

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldNotReceive('createNewsletterTemplate');
        $this->app->instance(SesBulkEmailService::class, $ses);

        $result = app(NewsletterTransport::class)->createTemplate($newsletter, $user, $newsletter->message);

        $this->assertNull($result);
    }

    public function test_send_preparation_preserves_current_template_in_export_for_both_transports(): void
    {
        foreach ([true, false] as $usesSesApi) {
            config(['mail.use_ses_api' => $usesSesApi]);
            $user = User::factory()->create();
            $this->actingAsTenant($user->client_id);
            $template = EmailTemplate::factory()->for($user)->create([
                'html_content' => '<header>OLD</header>{{ content }}',
            ]);
            $newsletter = Newsletter::factory()->for($user)->create();
            $message = $newsletter->message;
            $message->update([
                'email_template_id' => $template->id,
                'template_slot_content' => '<p>Body {{ name }}</p>',
                'html_content' => ContentSlot::hydrate($template->html_content, '<p>Body {{ name }}</p>'),
                'text_content' => 'OLD Body {{ name }}',
            ]);
            $mail = ContactMail::factory()->forNewsletter($newsletter)->create([
                'client_id' => $user->client_id,
                'contact_id' => Contact::factory()->for($user),
                'message_id' => $message->id,
                'template_data' => ['name' => 'Reader'],
            ]);
            $template->update(['html_content' => '<header>NEW</header>{{ content }}']);
            $ses = Mockery::mock(SesBulkEmailService::class);
            if ($usesSesApi) {
                $ses->shouldReceive('createNewsletterTemplate')->once()->andReturn('template-test');
            } else {
                $ses->shouldNotReceive('createNewsletterTemplate');
            }
            $this->app->instance(SesBulkEmailService::class, $ses);

            app(NewsletterTransport::class)->createTemplate($newsletter, $user, $message);
            $newsletter->update(['status' => 'sent']);
            $template->update(['html_content' => '<header>LATER</header>{{ content }}']);

            $stored = $message->fresh();
            $this->assertStringContainsString('NEW', $stored->html_content);
            $row = array_combine(NewsletterMailExport::columns(), (new NewsletterMailExport($newsletter->fresh(), $user->client))->row($mail->fresh()));
            $this->assertStringContainsString('NEW', $row['body']);
            $this->assertStringContainsString('Body Reader', $row['body']);
            $this->assertStringNotContainsString('OLD', $row['body']);
            $this->assertStringNotContainsString('LATER', $row['body']);
        }
    }

    public function test_delete_template_calls_ses_when_ses_api_enabled(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();
        EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldReceive('deleteTemplate')->once()->with('tpl-123', $user);
        $this->app->instance(SesBulkEmailService::class, $ses);

        app(NewsletterTransport::class)->deleteTemplate('tpl-123', $user);
    }

    public function test_delete_template_is_noop_when_ses_api_disabled(): void
    {
        config(['mail.use_ses_api' => false]);

        $user = User::factory()->create();

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldNotReceive('deleteTemplate');
        $this->app->instance(SesBulkEmailService::class, $ses);

        app(NewsletterTransport::class)->deleteTemplate('tpl-123', $user);
    }

    public function test_delete_template_is_noop_when_template_name_is_null(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldNotReceive('deleteTemplate');
        $this->app->instance(SesBulkEmailService::class, $ses);

        app(NewsletterTransport::class)->deleteTemplate(null, $user);
    }

    public function test_send_batch_calls_ses_bulk_api_when_ses_api_enabled(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create(['name' => 'Jane']);

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'message_id' => $newsletter->message->id,
            'subject' => 'Test',
            'from_email' => $identity->email,
            'from_name' => 'Sender',
            'status' => 'pending',
            'template_data' => ['name' => 'Jane', 'unsubscribe_url' => 'https://example.com/unsub'],
        ]);

        $capturedDestinations = null;

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldReceive('sendBulkTemplatedEmail')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, $destinations) use (&$capturedDestinations) {
                $capturedDestinations = $destinations;

                return true;
            });
        $this->app->instance(SesBulkEmailService::class, $ses);

        app(NewsletterTransport::class)->sendBatch(
            'tpl-123',
            $identity->email,
            'Sender',
            collect([$contact]),
            collect([$contactMail]),
            $user,
            [],
            'byomailer',
            $newsletter->message,
        );

        $this->assertCount(1, $capturedDestinations);
        $this->assertSame($contact->email, $capturedDestinations[0]['email']);
        $this->assertSame('Jane', $capturedDestinations[0]['data']['name']);
        $this->assertNotEmpty($capturedDestinations[0]['unsubscribe_url']);
    }

    /**
     * SES sends the `Send` event later. Until then, `sent_at` is the only proof
     * that SES accepted a mail, so a re-run of the chunk does not send it again.
     */
    public function test_send_batch_marks_the_mails_that_ses_accepted_as_sent(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count(2)->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update(['email_identity_id' => $identity->id]);

        $contactMails = $contacts->map(fn (Contact $contact) => ContactMail::factory()->forNewsletter($newsletter)->create([
            'client_id' => $user->client_id,
            'contact_id' => $contact->id,
            'message_id' => $newsletter->message->id,
            'status' => 'pending',
            'sent_at' => null,
        ]));

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldReceive('sendBulkTemplatedEmail')->once()->andReturn([
            ['Status' => 'SUCCESS', 'MessageId' => 'ses-message-1'],
            ['Status' => 'MESSAGE_REJECTED', 'Error' => 'Rejected'],
        ]);
        $this->app->instance(SesBulkEmailService::class, $ses);

        app(NewsletterTransport::class)->sendBatch(
            'tpl-123',
            $identity->email,
            'Sender',
            $contacts,
            $contactMails,
            $user,
            [],
            'byomailer',
            $newsletter->message,
        );

        $accepted = $contactMails[0]->fresh();
        $this->assertSame('sent', $accepted->status);
        $this->assertNotNull($accepted->sent_at);

        $rejected = $contactMails[1]->fresh();
        $this->assertSame('failed', $rejected->status);
        $this->assertNull($rejected->sent_at);
        $this->assertSame('Rejected', $rejected->failure_reason);
    }

    public function test_send_batch_forwards_message_to_ses_for_configuration_set_resolution(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'my-config-set',
        ]);
        $contact = Contact::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'message_id' => $newsletter->message->id,
            'subject' => 'Test',
            'from_email' => $identity->email,
            'from_name' => 'Sender',
            'status' => 'pending',
            'template_data' => ['unsubscribe_url' => 'https://example.com/unsub'],
        ]);

        $capturedMessage = null;

        $ses = Mockery::mock(SesBulkEmailService::class);
        $ses->shouldReceive('sendBulkTemplatedEmail')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, $destinations, $u, $defaults, $tenant, $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            });
        $this->app->instance(SesBulkEmailService::class, $ses);

        app(NewsletterTransport::class)->sendBatch(
            'tpl-123',
            $identity->email,
            'Sender',
            collect([$contact]),
            collect([$contactMail]),
            $user,
            [],
            'byomailer',
            $newsletter->message->fresh(),
        );

        $this->assertNotNull($capturedMessage);
        $this->assertSame('my-config-set', $capturedMessage->emailIdentity?->configuration_set_name);
    }

    public function test_send_batch_sends_via_mailer_when_ses_api_disabled(): void
    {
        Mail::fake();
        config(['mail.use_ses_api' => false]);

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count(2)->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $contactMails = $contacts->map(fn (Contact $contact) => ContactMail::create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'message_id' => $newsletter->message->id,
            'subject' => 'Test',
            'from_email' => $identity->email,
            'from_name' => 'Sender',
            'status' => 'pending',
            'template_data' => ['unsubscribe_url' => 'https://example.com/unsub'],
        ]));

        $mockMailerFactory = Mockery::mock(UserMailerFactory::class);
        $mockMailerFactory->shouldReceive('mailerFor')
            ->with(Mockery::on(fn ($u) => $u->is($user)))
            ->andReturnUsing(fn () => new PendingMail(Mail::getFacadeRoot()));
        $this->app->instance(UserMailerFactory::class, $mockMailerFactory);

        app(NewsletterTransport::class)->sendBatch(
            null,
            $identity->email,
            'Sender',
            $contacts,
            $contactMails,
            $user,
            [],
            'byomailer',
            $newsletter->message,
        );

        Mail::assertSent(NewsletterMail::class, 2);

        foreach ($contacts as $contact) {
            Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $m) => $m->hasTo($contact->email));
        }

        foreach ($contactMails as $contactMail) {
            $contactMail->refresh();
            $this->assertSame('sent', $contactMail->status);
            $this->assertNotNull($contactMail->sent_at);
        }
    }

    public function test_get_chunk_size_uses_ses_rate_when_ses_api_enabled(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();

        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user->client)->for($user)->withTenant()->create(['max_send_rate' => 50]);

        $chunkSize = app(NewsletterTransport::class)->getChunkSize($user);

        $this->assertSame(50, $chunkSize);
    }

    public function test_get_chunk_size_returns_fixed_value_when_ses_api_disabled(): void
    {
        config(['mail.use_ses_api' => false]);

        $user = User::factory()->create();

        $chunkSize = app(NewsletterTransport::class)->getChunkSize($user);

        $this->assertSame(50, $chunkSize);
    }
}
