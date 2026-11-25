<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Exceptions\MissingSesPermissionException;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Jobs\SendNewsletterJob;
use App\Mail\NewsletterBlockedMail;
use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Services\SendNewsletterService;
use App\Services\SesClientManager;
use App\Services\UserMailerFactory;
use App\Support\Tenant;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

class SesPermissionCheckTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_newsletter_scheduling_blocked_when_missing_ses_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => false,
            'can_send_bulk_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        Bus::fake();

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors('ses_permissions');
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_newsletter_scheduling_allowed_when_permissions_present(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
            'permissions_checked_at' => now(),
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        Bus::fake();

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect(route('newsletters.show', $newsletter));
        Bus::assertDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_newsletter_draft_allowed_without_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => false,
            'can_send_bulk_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect(route('newsletters.index'));
    }

    public function test_newsletter_scheduling_allowed_when_permissions_not_yet_checked(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => false,
            'can_send_bulk_email' => false,
            'permissions_checked_at' => null,
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        Bus::fake();

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect(route('newsletters.show', $newsletter));
        Bus::assertDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_newsletter_future_schedule_blocked_when_missing_ses_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => false,
            'can_send_bulk_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create();

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        Bus::fake();

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'schedule',
            'email_identity_id' => $identity->id,
            'scheduled_for' => now()->addDay()->format('Y-m-d\TH:i'),
        ]);

        $response->assertSessionHasErrors('ses_permissions');
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_sequence_step_skipped_when_missing_send_permission(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->where('mailable_id', $step->id)
            ->first();

        $this->assertNotNull($contactMail);
        $this->assertSame('pending', $contactMail->status);

        $this->withoutSesSendingLimit();

        $this->artisan('contact-mails:send-due-sequence-steps')
            ->assertSuccessful();

        $contactMail->refresh();
        $this->assertSame('failed', $contactMail->status);
        $this->assertSame('ses_permission_missing', $contactMail->failure_reason);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_sequence_step_sent_when_send_permission_present(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        Mail::fake();

        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLE123456789',
            'secret_key' => 'example-secret-key-1234567890',
            'region' => 'us-east-1',
            'can_send_email' => true,
            'permissions_checked_at' => now(),
        ]);

        $contact = Contact::factory()->for($user)->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);

        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $mockMailerFactory = Mockery::mock(UserMailerFactory::class);
        $mockMailerFactory->shouldReceive('mailerFor')
            ->once()
            ->andReturnUsing(fn () => new PendingMail(Mail::getFacadeRoot()));
        $this->app->instance(UserMailerFactory::class, $mockMailerFactory);

        $this->withoutSesSendingLimit();

        $this->artisan('contact-mails:send-due-sequence-steps')
            ->assertSuccessful();

        Mail::assertSent(NewsletterMail::class, 1);
    }

    public function test_recheck_permissions_endpoint_updates_permissions(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => false,
            'can_send_email' => false,
            'permissions_checked_at' => null,
        ]);

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->once()
            ->andReturn([
                'can_create_template' => true,
                'can_delete_template' => true,
                'can_send_bulk_email' => true,
                'can_send_email' => true,
            ]);

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $response = $this->actingAs($user)
            ->postJson(route('email-credentials.recheck-permissions'));

        $response->assertOk()->assertJson([
            'success' => true,
            'permissions' => [
                'can_create_template' => true,
                'can_delete_template' => true,
                'can_send_bulk_email' => true,
                'can_send_email' => true,
            ],
        ]);
    }

    public function test_recheck_permissions_returns_error_without_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('email-credentials.recheck-permissions'));

        $response->assertStatus(404)->assertJson(['error' => 'No SES credentials found.']);
    }

    public function test_recheck_permissions_handles_api_failure(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->once()
            ->andThrow(new RuntimeException('AWS connection failed'));

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $response = $this->actingAs($user)
            ->postJson(route('email-credentials.recheck-permissions'));

        $response->assertStatus(500)->assertJson([
            'error' => 'Failed to check permissions: AWS connection failed',
        ]);
    }

    public function test_credential_resource_includes_permission_fields(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => true,
            'can_delete_template' => false,
            'can_send_bulk_email' => true,
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('email-credentials.index'));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/EmailCredentials')
            ->has('credential', fn ($credential) => $credential
                ->where('can_create_template', true)
                ->where('can_delete_template', false)
                ->where('can_send_bulk_email', true)
                ->where('can_send_email', false)
                ->has('permissions_checked_at')
                ->etc()
            )
        );
    }

    public function test_sync_command_updates_permissions(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'can_create_template' => false,
            'can_delete_template' => false,
            'can_send_bulk_email' => false,
            'can_send_email' => false,
            'permissions_checked_at' => null,
        ]);

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdatePermissions')
            ->once()
            ->andReturnUsing(function ($user) {
                $user->sesCredential->update([
                    'can_create_template' => true,
                    'can_delete_template' => true,
                    'can_send_bulk_email' => true,
                    'can_send_email' => true,
                    'permissions_checked_at' => now(),
                ]);

                return [
                    'can_create_template' => true,
                    'can_delete_template' => true,
                    'can_send_bulk_email' => true,
                    'can_send_email' => true,
                ];
            });

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $this->artisan('ses:sync-configuration-sets')
            ->assertSuccessful();

        $credential = $user->sesCredential()->withoutGlobalScopes()->first();
        $this->assertTrue($credential->can_create_template);
        $this->assertTrue($credential->can_delete_template);
        $this->assertTrue($credential->can_send_bulk_email);
        $this->assertTrue($credential->can_send_email);
    }

    public function test_send_newsletter_service_throws_when_missing_bulk_permissions(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create([
            'can_create_template' => false,
            'can_send_bulk_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create(['status' => 'queued']);

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldNotReceive('createTemplate');
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);
        $batch->shouldNotReceive('add');

        $this->expectException(MissingSesPermissionException::class);
        $this->expectExceptionMessage('ses:CreateEmailTemplate');

        app(SendNewsletterService::class)->send($newsletter, $batch);
    }

    public function test_send_newsletter_service_proceeds_when_permissions_granted(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create([
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => true,
            'permissions_checked_at' => now(),
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create(['status' => 'queued']);

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->once()->andReturn('tpl-test');
        $transport->shouldReceive('getChunkSize')->once()->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnSelf();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $batch->shouldHaveReceived('add')->once();
    }

    public function test_send_newsletter_service_checks_send_email_for_mailer_path(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create([
            'can_create_template' => true,
            'can_delete_template' => true,
            'can_send_bulk_email' => true,
            'can_send_email' => false,
            'permissions_checked_at' => now(),
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create(['status' => 'queued']);

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(false);
        $transport->shouldNotReceive('createTemplate');
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);

        $this->expectException(MissingSesPermissionException::class);
        $this->expectExceptionMessage('ses:SendEmail');

        app(SendNewsletterService::class)->send($newsletter, $batch);
    }

    public function test_send_newsletter_service_skips_check_when_permissions_not_yet_checked(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create([
            'can_create_template' => false,
            'can_send_bulk_email' => false,
            'permissions_checked_at' => null,
        ]);

        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->create(['status' => 'queued']);

        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Test</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Test',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->once()->andReturn('tpl-test');
        $transport->shouldReceive('getChunkSize')->once()->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnSelf();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $batch->shouldHaveReceived('add')->once();
    }

    public function test_newsletter_blocked_mail_sent_on_permission_failure(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);

        $exception = new MissingSesPermissionException(['ses:CreateEmailTemplate', 'ses:SendBulkEmail'], $user->id);

        $job = new SendNewsletterJob($newsletter);
        $job->failed($exception);

        $this->assertSame(NewsletterStatus::Failed, $newsletter->fresh()->status);

        Queue::assertPushed(SendQueuedMailable::class, function (SendQueuedMailable $pushed) use ($user, $newsletter) {
            return $pushed->mailable instanceof NewsletterBlockedMail
                && $pushed->mailable->recipient->is($user)
                && $pushed->mailable->newsletter->is($newsletter)
                && $pushed->mailable->missingPermissions === ['ses:CreateEmailTemplate', 'ses:SendBulkEmail'];
        });
    }

    public function test_newsletter_blocked_mail_not_sent_for_other_exceptions(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);

        $job = new SendNewsletterJob($newsletter);
        $job->failed(new RuntimeException('Something else broke'));

        $this->assertSame(NewsletterStatus::Failed, $newsletter->fresh()->status);
        Queue::assertNotPushed(SendQueuedMailable::class);
    }
}
