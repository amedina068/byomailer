<?php

namespace Tests\Feature;

use App\Exceptions\SendingBlockedException;
use App\Jobs\SendNewsletterChunkJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\EmailSendingGate;
use App\Services\NewsletterTransport;
use App\Services\SendNewsletterService;
use App\Services\UserMailerFactory;
use App\Support\Tenant;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Queued work sends mail as a user: a newsletter as its author, a sequence
 * step as the owner of the contact. A deactivated user sends nothing.
 */
class DeactivatedUserSendingTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function readyNewsletter(User $user): Newsletter
    {
        $list = MailList::factory()->for($user)->create();
        Contact::factory()->for($user)->create()->lists()->attach($list);

        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->withListFilters([$list->id])->create();

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        return $newsletter->fresh();
    }

    public function test_the_gate_blocks_a_deactivated_user(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $decision = app(EmailSendingGate::class)->evaluate($user);

        $this->assertFalse($decision->allowed);
        $this->assertSame(EmailSendingGate::USER_DEACTIVATED_REASON, $decision->reason);
        $this->assertSame(EmailSendingGate::USER_DEACTIVATED_MESSAGE, $decision->message);
    }

    public function test_a_queued_newsletter_of_a_deactivated_user_is_not_sent(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = $this->readyNewsletter($user);
        $newsletter->update(['status' => 'queued']);
        $user->deactivate();

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldNotReceive('createTemplate');
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);
        $batch->shouldNotReceive('add');

        $this->expectException(SendingBlockedException::class);
        $this->expectExceptionMessage(EmailSendingGate::USER_DEACTIVATED_REASON);

        app(SendNewsletterService::class)->send($newsletter, $batch);
    }

    public function test_a_newsletter_chunk_that_is_already_queued_sends_nothing(): void
    {
        $user = User::factory()->create();
        $newsletter = Tenant::run($user->client_id, fn (): Newsletter => $this->readyNewsletter($user)->load('message'));
        $contacts = Contact::factory()->count(2)->for($user)->create();

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $newsletter->message->from_email,
            fromName: 'Sender',
            contactIds: $contacts->pluck('id')->all(),
            templateName: 'tpl-1',
            message: $newsletter->message,
            tenantName: 'byomailer',
        );

        $user->deactivate();

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldNotReceive('sendBatch');

        $job->handle($transport);

        $this->assertSame(0, ContactMail::withoutGlobalScopes()->count());
    }

    public function test_a_sequence_mail_of_a_deactivated_user_fails_with_the_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        $this->withoutSesSendingLimit();

        $mailerFactory = Mockery::mock(UserMailerFactory::class);
        $mailerFactory->shouldNotReceive('mailerFor');
        $this->app->instance(UserMailerFactory::class, $mailerFactory);

        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'status' => 'live']);
        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));
        $user->deactivate();

        $this->artisan('contact-mails:send-due-sequence-steps')->assertSuccessful();

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_id', $step->id)
            ->firstOrFail();

        $this->assertSame('failed', $contactMail->status);
        $this->assertSame(EmailSendingGate::USER_DEACTIVATED_REASON, $contactMail->failure_reason);
    }
}
