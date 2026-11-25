<?php

namespace Tests\Feature;

use App\Exceptions\SendingBlockedException;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
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
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SesAccountStatusGateTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function gate(): EmailSendingGate
    {
        return app(EmailSendingGate::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function credential(User $user, array $attributes = []): EmailCredential
    {
        return EmailCredential::factory()->for($user)->withTenant()->create([
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
            'permissions_checked_at' => now(),
            ...$attributes,
        ]);
    }

    private function pausedCredential(User $user): EmailCredential
    {
        return $this->credential($user, [
            'sending_enabled' => false,
            'enforcement_status' => 'SHUTDOWN',
            'quota_fetched_at' => now(),
        ]);
    }

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

    public function test_gate_allows_user_without_credential(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $this->assertTrue($this->gate()->evaluate($user)->allowed);
    }

    public function test_gate_allows_credential_that_was_never_fetched(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $this->credential($user, ['sending_enabled' => false, 'quota_fetched_at' => null]);

        $this->assertTrue($this->gate()->evaluate($user)->allowed);
    }

    public function test_gate_allows_probation_with_sending_enabled(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $this->credential($user, [
            'sending_enabled' => true,
            'enforcement_status' => 'PROBATION',
            'quota_fetched_at' => now(),
        ]);

        $this->assertTrue($this->gate()->evaluate($user)->allowed);
    }

    public function test_gate_blocks_when_ses_sending_is_disabled(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $this->pausedCredential($user);

        $decision = $this->gate()->evaluate($user);

        $this->assertFalse($decision->allowed);
        $this->assertSame(EmailSendingGate::SES_SENDING_DISABLED_REASON, $decision->reason);
        $this->assertSame(EmailSendingGate::SES_SENDING_DISABLED_MESSAGE, $decision->message);
    }

    public function test_newsletter_schedule_is_blocked_when_ses_sending_is_disabled(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $this->pausedCredential($user);
        $newsletter = $this->readyNewsletter($user);

        Bus::fake();

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $newsletter->message->email_identity_id,
        ]);

        $response->assertSessionHasErrors(['ses_account' => EmailSendingGate::SES_SENDING_DISABLED_MESSAGE]);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_api_live_send_is_blocked_with_ses_account_error(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $this->pausedCredential($user);
        $newsletter = $this->readyNewsletter($user);

        Bus::fake();

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'live',
            'scheduled_at' => now()->toDateTimeString(),
            'email_identity_id' => $newsletter->message->email_identity_id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ses_account' => EmailSendingGate::SES_SENDING_DISABLED_MESSAGE]);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_send_newsletter_service_refuses_when_ses_sending_is_disabled(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $this->pausedCredential($user);
        $newsletter = $this->readyNewsletter($user);
        $newsletter->update(['status' => 'queued']);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldNotReceive('createTemplate');
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);
        $batch->shouldNotReceive('add');

        $this->expectException(SendingBlockedException::class);
        $this->expectExceptionMessage(EmailSendingGate::SES_SENDING_DISABLED_REASON);

        app(SendNewsletterService::class)->send($newsletter, $batch);
    }

    public function test_sequence_cron_marks_mail_failed_when_ses_sending_is_disabled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));
        $this->withoutSesSendingLimit();

        $mailerFactory = Mockery::mock(UserMailerFactory::class);
        $mailerFactory->shouldNotReceive('mailerFor');
        $this->app->instance(UserMailerFactory::class, $mailerFactory);

        $user = User::factory()->create();
        $this->pausedCredential($user);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['delay_days' => 0, 'status' => 'live']);
        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $this->artisan('contact-mails:send-due-sequence-steps')->assertSuccessful();

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_id', $step->id)
            ->firstOrFail();

        $this->assertSame('failed', $contactMail->status);
        $this->assertSame(EmailSendingGate::SES_SENDING_DISABLED_REASON, $contactMail->failure_reason);
    }

    public function test_ses_account_status_prop_is_shared_for_own_credential(): void
    {
        $user = User::factory()->create();
        $this->credential($user, [
            'sending_enabled' => true,
            'enforcement_status' => 'PROBATION',
            'quota_fetched_at' => now(),
        ]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sesAccountStatus.enforcement_status', 'PROBATION')
                ->where('sesAccountStatus.sending_enabled', true));
    }

    public function test_ses_account_status_prop_is_null_without_credential(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('sesAccountStatus', null));
    }
}
