<?php

namespace Tests\Feature;

use App\Jobs\SendNewsletterChunkJob;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\Concerns\WorksTheSesSendingQueue;
use Tests\TestCase;

class SendNewsletterChunkJobTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;
    use WorksTheSesSendingQueue;

    /**
     * A failed Mockery expectation throws before Laravel resets the clock.
     * Reset it first, so a travel in one test cannot leak into the next.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_batch_for_chunk_of_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count(3)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $capturedContacts = null;
        $capturedContactMails = null;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, Collection $c, Collection $cm) use (&$capturedContacts, &$capturedContactMails) {
                $capturedContacts = $c;
                $capturedContactMails = $cm;

                return true;
            });

        $message = $newsletter->message;

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Test Sender',
            contactIds: $contacts->pluck('id')->all(),
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $this->assertSame(3, ContactMail::count());
        $this->assertCount(3, $capturedContacts);
        $this->assertCount(3, $capturedContactMails);

        foreach ($contacts as $contact) {
            $this->assertDatabaseHas('contact_mails', [
                'contact_id' => $contact->id,
                'mailable_type' => Newsletter::class,
                'mailable_id' => $newsletter->id,
                'status' => 'pending',
            ]);
        }
    }

    public function test_it_forwards_message_to_transport_for_configuration_set_resolution(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'my-config-set',
        ]);
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $capturedMessage = null;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, $c, $cm, $u, $defaults, $tenant, $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            });

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Sender',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $newsletter->message->fresh(),
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $this->assertNotNull($capturedMessage);
        $this->assertSame('my-config-set', $capturedMessage->emailIdentity?->configuration_set_name);
    }

    public function test_it_creates_contact_mail_with_template_data(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['first_name' => 'John'],
        ]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Hello {{first_name}}',
            'html_content' => '<p>Hello {{ first_name }}, click <a href="{{ unsubscribe_url }}">here</a> to unsubscribe.</p>',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        $message = $newsletter->message;

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Test Sender',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $contactMail = ContactMail::first();
        $this->assertNotNull($contactMail);
        $this->assertSame('Hello {{first_name}}', $contactMail->subject);
        $this->assertSame($identity->email, $contactMail->from_email);
        $this->assertSame('Test Sender', $contactMail->from_name);
        $this->assertSame('John', $contactMail->template_data['first_name']);
        $this->assertArrayHasKey('unsubscribe_url', $contactMail->template_data);
        $this->assertArrayHasKey('preferences_url', $contactMail->template_data);
        $this->assertStringContainsString('/preferences/', $contactMail->template_data['preferences_url']);
        $this->assertStringContainsString('signature=', $contactMail->template_data['preferences_url']);
    }

    public function test_it_skips_processing_when_batch_is_cancelled(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldNotReceive('sendBatch');

        $message = $newsletter->message;

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: 'test@example.com',
            fromName: 'Test',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $batch = Bus::batch([])->dispatch();
        $batch->cancel();

        $job->withBatchId($batch->id);
        $job->handle($transport);

        $this->assertSame(0, ContactMail::count());
    }

    public function test_it_handles_empty_contact_ids_gracefully(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldNotReceive('sendBatch');

        $message = $newsletter->message;

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: 'test@example.com',
            fromName: 'Test',
            contactIds: [],
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $this->assertSame(0, ContactMail::count());
    }

    public function test_it_does_not_duplicate_contact_mail_records(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $message = $newsletter->message;

        ContactMail::create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'message_id' => $message->id,
            'subject' => 'Original',
            'status' => 'pending',
        ]);

        $capturedContactMails = null;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, Collection $c, Collection $cm) use (&$capturedContactMails) {
                $capturedContactMails = $cm;

                return true;
            });

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Test Sender',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $contactMail = ContactMail::sole();
        $this->assertSame('pending', $contactMail->status);
        $this->assertSame([$contactMail->id], $capturedContactMails->modelKeys());
        $this->assertSame($contactMail->unsubscribe_link, $contactMail->template_data['unsubscribe_url']);
    }

    /**
     * A worker can die after the chunk writes its rows and before SES accepts
     * the mails. The retry sends the same rows. It does not add rows.
     */
    public function test_a_rerun_after_the_worker_died_sends_the_pending_mails_once(): void
    {
        [$job, $contacts] = $this->chunkJobForNewContacts(3);

        $deadWorker = Mockery::mock(NewsletterTransport::class);
        $deadWorker->shouldReceive('sendBatch')->once()->andThrow(new RuntimeException('Worker died'));

        try {
            $job->handle($deadWorker);
            $this->fail('The first run must throw.');
        } catch (RuntimeException) {
        }

        $rowsOfFirstRun = ContactMail::query()->orderBy('id')->get();
        $this->assertCount(3, $rowsOfFirstRun);

        $capturedContacts = null;
        $capturedContactMails = null;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, Collection $c, Collection $cm) use (&$capturedContacts, &$capturedContactMails) {
                $capturedContacts = $c;
                $capturedContactMails = $cm;

                return true;
            });

        $job->handle($transport);

        $this->assertSame(3, ContactMail::count());
        $this->assertEqualsCanonicalizing($contacts->modelKeys(), $capturedContacts->modelKeys());
        $this->assertEqualsCanonicalizing($rowsOfFirstRun->modelKeys(), $capturedContactMails->modelKeys());
        $this->assertEqualsCanonicalizing(
            $rowsOfFirstRun->pluck('template_data')->all(),
            $capturedContactMails->pluck('template_data')->all(),
        );
    }

    /**
     * Only a `pending` mail with no `sent_at` is still unsent. SES accepted the
     * other mails, or the send closed them, so a re-run must not send them again.
     */
    public function test_a_rerun_sends_only_the_mails_that_ses_did_not_accept(): void
    {
        [$job, $contacts, $newsletter] = $this->chunkJobForNewContacts(4);

        $rowFor = fn (Contact $contact, array $attributes): ContactMail => ContactMail::factory()->forNewsletter($newsletter)->create([
            'client_id' => $contact->client_id,
            'contact_id' => $contact->id,
            'message_id' => $newsletter->message->id,
            ...$attributes,
        ]);

        $rowFor($contacts[0], ['status' => 'sent', 'sent_at' => now()]);
        $rowFor($contacts[1], ['status' => 'delivered', 'sent_at' => now()]);
        $rowFor($contacts[2], ['status' => 'failed', 'sent_at' => null]);
        $unsent = $rowFor($contacts[3], ['status' => 'pending', 'sent_at' => null]);

        $capturedContacts = null;
        $capturedContactMails = null;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, Collection $c, Collection $cm) use (&$capturedContacts, &$capturedContactMails) {
                $capturedContacts = $c;
                $capturedContactMails = $cm;

                return true;
            });

        $job->handle($transport);

        $this->assertSame(4, ContactMail::count());
        $this->assertSame([$contacts[3]->id], $capturedContacts->modelKeys());
        $this->assertSame([$unsent->id], $capturedContactMails->modelKeys());
    }

    /**
     * The chunk must not run queries for each contact. A chunk of 20 contacts
     * runs the same number of queries as a chunk of 2.
     */
    public function test_the_queries_of_a_chunk_do_not_grow_with_the_number_of_contacts(): void
    {
        $smallChunkQueries = $this->countQueriesOfChunk(2);
        $largeChunkQueries = $this->countQueriesOfChunk(20);

        $this->assertSame($smallChunkQueries, $largeChunkQueries);
    }

    private function countQueriesOfChunk(int $contactCount): int
    {
        [$job, $contacts, $newsletter] = $this->chunkJobForNewContacts($contactCount);
        $newsletter->message->update([
            'subject' => 'Hello {{ first_name }}',
            'html_content' => '<p>Hi {{ first_name }}</p><p>{{ postal_address }}</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
        ]);
        $contacts->each(fn (Contact $contact) => $contact->update(['extra_attributes' => ['first_name' => 'Reader '.$contact->id]]));
        $postalAddress = $newsletter->client->postal_address;

        $job = $this->chunkJobFor($newsletter, $contacts);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $job->handle($transport);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $contactMails = ContactMail::query()->where('mailable_id', $newsletter->id)->get();
        $this->assertCount($contactCount, $contactMails);

        foreach ($contactMails as $contactMail) {
            $this->assertSame('Reader '.$contactMail->contact_id, $contactMail->template_data['first_name']);
            $this->assertSame($postalAddress, $contactMail->template_data['postal_address']);
            $this->assertSame($contactMail->unsubscribe_link, $contactMail->template_data['unsubscribe_url']);
            $this->assertSame($contactMail->preferences_link, $contactMail->template_data['preferences_url']);
            $this->assertSame($newsletter->client_id, $contactMail->client_id);
            $this->assertSame($newsletter->message->id, $contactMail->message_id);
        }

        return $queryCount;
    }

    public function test_it_skips_contacts_who_already_received_newsletter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count(3)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        ContactMail::create([
            'contact_id' => $contacts[0]->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => 'Already Sent',
            'status' => 'sent',
            'sent_at' => now()->subHour(),
        ]);

        $capturedContacts = null;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, Collection $c) use (&$capturedContacts) {
                $capturedContacts = $c;

                return true;
            });

        $message = $newsletter->message;

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Test Sender',
            contactIds: $contacts->pluck('id')->all(),
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $this->assertCount(2, $capturedContacts);
        $this->assertSame(3, ContactMail::count());
    }

    public function test_it_skips_all_contacts_when_all_already_received(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);

        ContactMail::create([
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $newsletter->id,
            'subject' => 'Already Sent',
            'status' => 'sent',
            'sent_at' => now()->subHour(),
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldNotReceive('sendBatch');

        $message = $newsletter->message;

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Test Sender',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $this->assertSame(1, ContactMail::count());
    }

    public function test_it_has_rate_limited_with_redis_middleware(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: 'test@example.com',
            fromName: 'Test',
            contactIds: [1],
            templateName: 'tpl-123',
            message: $newsletter->message,
            tenantName: 'byomailer',
        );

        $middleware = $job->middleware();

        $this->assertNotEmpty($middleware);
        $this->assertInstanceOf(RateLimitedWithRedis::class, $middleware[0]);
    }

    /**
     * All chunks of a newsletter go on the queue at the start of the send. The
     * `ses-sending` limiter then holds the end of a large list for hours. A
     * chunk must not expire while it waits for its turn.
     */
    public function test_a_chunk_that_the_limiter_holds_for_more_than_two_hours_still_sends(): void
    {
        [$job, $contacts] = $this->chunkJobForNewContacts(3);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();
        $this->instance(NewsletterTransport::class, $transport);

        $this->refuseSesSendingSlots(30);
        dispatch($job)->onConnection('database');

        for ($release = 1; $release <= 30; $release++) {
            $this->workOneSesSendingJob('newsletter');
            $this->travel(5)->minutes();
        }

        $this->assertSame(30, DB::table('jobs')->value('attempts'));

        $this->workOneSesSendingJob('newsletter');

        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseEmpty('failed_jobs');
        $this->assertSame(3, ContactMail::query()->where('status', 'pending')->whereIn('contact_id', $contacts->modelKeys())->count());
    }

    /**
     * A limiter release is not a failure. A real exception still fails the
     * chunk at once, and `failed()` marks its mails `failed`.
     */
    public function test_a_chunk_that_throws_after_limiter_releases_fails_and_marks_its_mails_failed(): void
    {
        [$job, $contacts] = $this->chunkJobForNewContacts(2);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once()->andThrow(new RuntimeException('SES is down'));
        $this->instance(NewsletterTransport::class, $transport);

        $this->refuseSesSendingSlots(5);
        dispatch($job)->onConnection('database');

        for ($release = 1; $release <= 6; $release++) {
            $this->workOneSesSendingJob('newsletter');
            $this->travel(1)->minute();
        }

        $this->assertDatabaseEmpty('jobs');
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(2, ContactMail::query()
            ->whereIn('contact_id', $contacts->modelKeys())
            ->where('status', 'failed')
            ->where('failure_reason', 'SES is down')
            ->count());
    }

    public function test_ses_sending_rate_limiter_is_registered(): void
    {
        $limiter = RateLimiter::limiter('ses-sending');

        $this->assertNotNull($limiter);
        $this->assertIsCallable($limiter);
    }

    public function test_ses_sending_rate_limiter_returns_per_second_limit_by_user(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->production()->create(['max_send_rate' => 14]);

        $newsletter = Newsletter::factory()->for($user)->create();

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: 'test@example.com',
            fromName: 'Test',
            contactIds: [1],
            templateName: 'tpl-123',
            message: $newsletter->message,
            tenantName: 'byomailer',
        );

        $limiter = RateLimiter::limiter('ses-sending');
        $limit = $limiter($job);

        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(1, $limit->maxAttempts);
        $this->assertSame(1, $limit->decaySeconds);
    }

    public function test_it_merges_content_variable_defaults(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create([
                'status' => 'queued',
                'content_variables' => ['discount_code' => 'SAVE20'],
            ]);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'html_content' => '<p>Use code {{ discount_code }}</p>',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Test Sender',
            contactIds: [$contact->id],
            templateName: null,
            message: $newsletter->message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $contactMail = ContactMail::first();
        $this->assertSame('SAVE20', $contactMail->template_data['discount_code']);
    }

    public function test_it_skips_contacts_who_became_unsendable_after_dispatch(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $otherUser = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $sendable = Contact::factory()->for($user)->create();
        $unsubscribed = Contact::factory()->for($user)->create();
        $sunset = Contact::factory()->for($user)->create();
        $blacklisted = Contact::factory()->for($user)->create();
        $globallyBlacklisted = Contact::factory()->for($user)->create();
        $otherClient = Contact::factory()->for($otherUser)->create();

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Sender',
            contactIds: [$sendable->id, $unsubscribed->id, $sunset->id, $blacklisted->id, $globallyBlacklisted->id, $otherClient->id],
            templateName: 'tpl-123',
            message: $newsletter->message,
            tenantName: 'byomailer',
        );

        // The state changes after the dispatch and before the chunk runs.
        $unsubscribed->forceFill(['unsubscribed_at' => now(), 'unsubscribed_by_contact' => true])->save();
        $sunset->forceFill(['sunset_at' => now()])->save();
        BlacklistedEmail::factory()->for($user)->create(['email' => $blacklisted->email]);
        BlacklistedEmail::factory()->global()->create(['email' => $globallyBlacklisted->email, 'client_id' => null]);

        $capturedContacts = null;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')
            ->once()
            ->withArgs(function ($tpl, $from, $fromName, Collection $c) use (&$capturedContacts) {
                $capturedContacts = $c;

                return true;
            });

        $job->handle($transport);

        $this->assertSame([$sendable->id], $capturedContacts->pluck('id')->all());
        $this->assertSame([$sendable->id], ContactMail::query()->pluck('contact_id')->all());
    }

    public function test_it_sends_nothing_when_every_contact_unsubscribed_after_dispatch(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Sender',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $newsletter->message,
            tenantName: 'byomailer',
        );

        $contact->forceFill(['unsubscribed_at' => now(), 'unsubscribed_by_contact' => true])->save();

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldNotReceive('sendBatch');

        $job->handle($transport);

        $this->assertSame(0, ContactMail::count());
    }

    /**
     * @return array{0: SendNewsletterChunkJob, 1: EloquentCollection<int, Contact>, 2: Newsletter}
     */
    private function chunkJobForNewContacts(int $count): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create(['max_send_rate' => 14]);
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count($count)->for($user)->create();

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        return [$this->chunkJobFor($newsletter, $contacts), $contacts, $newsletter];
    }

    /**
     * @param  EloquentCollection<int, Contact>  $contacts
     */
    private function chunkJobFor(Newsletter $newsletter, EloquentCollection $contacts): SendNewsletterChunkJob
    {
        return new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $newsletter->user,
            fromEmail: 'sender@example.com',
            fromName: 'Sender',
            contactIds: $contacts->modelKeys(),
            templateName: 'tpl-123',
            message: $newsletter->message()->firstOrFail(),
            tenantName: 'byomailer',
        );
    }
}
