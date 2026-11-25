<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Jobs\SendNewsletterChunkJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Services\SendNewsletterService;
use Illuminate\Bus\Batch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SendNewsletterServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function fakeBatch(): Batch
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnSelf();

        return $batch;
    }

    public function test_it_skips_already_sent_newsletters(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'sent']);

        $batch = $this->fakeBatch();
        app(SendNewsletterService::class)->send($newsletter, $batch);

        $batch->shouldNotHaveReceived('add');
    }

    public function test_it_skips_ab_testing_newsletters(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'ab_testing']);

        $batch = $this->fakeBatch();
        app(SendNewsletterService::class)->send($newsletter, $batch);

        $batch->shouldNotHaveReceived('add');
    }

    public function test_it_dispatches_chunk_jobs_via_transport(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->once()->andReturn('tpl-123');
        $transport->shouldReceive('getChunkSize')->once()->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = $this->fakeBatch();
        app(SendNewsletterService::class)->send($newsletter, $batch);

        $newsletter->refresh();
        $this->assertSame('tpl-123', $newsletter->template_name);
        $this->assertSame(1, $newsletter->total_contacts_reached);

        $batch->shouldHaveReceived('add')->once();
    }

    public function test_it_passes_email_identity_configuration_set_to_chunk_jobs(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-from-identity',
        ]);
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->once()->andReturn('tpl-123');
        $transport->shouldReceive('getChunkSize')->once()->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        $captured = null;

        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')
            ->once()
            ->andReturnUsing(function (array $jobs) use (&$captured, $batch) {
                $captured = $jobs[0];

                return $batch;
            });

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $this->assertInstanceOf(SendNewsletterChunkJob::class, $captured);
        $this->assertSame('cs-from-identity', $captured->message->emailIdentity?->configuration_set_name);
    }

    public function test_it_does_not_store_template_name_when_transport_returns_null(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->once()->andReturn(null);
        $transport->shouldReceive('getChunkSize')->once()->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = $this->fakeBatch();
        app(SendNewsletterService::class)->send($newsletter, $batch);

        $newsletter->refresh();
        $this->assertNull($newsletter->template_name);

        $batch->shouldHaveReceived('add')->once();
    }

    public function test_it_excludes_contacts_that_already_have_a_contact_mail_for_the_newsletter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $alreadySent = Contact::factory()->for($user)->create();
        $alreadySent->lists()->attach($list);

        $notYetSent = Contact::factory()->for($user)->create();
        $notYetSent->lists()->attach($list);

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        ContactMail::factory()
            ->for($alreadySent, 'contact')
            ->forNewsletter($newsletter)
            ->sent()
            ->create();

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->once()->andReturn('tpl-123');
        $transport->shouldReceive('getChunkSize')->once()->andReturn(50);
        $this->app->instance(NewsletterTransport::class, $transport);

        $batch = $this->fakeBatch();
        app(SendNewsletterService::class)->send($newsletter, $batch);

        $newsletter->refresh();
        $this->assertSame(1, $newsletter->total_contacts_reached);
        $batch->shouldHaveReceived('add')->once();
    }

    public function test_it_marks_newsletter_as_draft_when_missing_message(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'queued']);
        $newsletter->message()->delete();

        $batch = $this->fakeBatch();
        app(SendNewsletterService::class)->send($newsletter, $batch);

        $newsletter->refresh();
        $this->assertSame(NewsletterStatus::Draft, $newsletter->status);

        $batch->shouldNotHaveReceived('add');
    }

    public function test_it_marks_ab_test_as_draft_when_message_b_is_missing(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'queued',
            'ab_test_enabled' => true,
        ]);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'variant' => 'a',
        ]);

        $batch = $this->fakeBatch();
        app(SendNewsletterService::class)->send($newsletter, $batch);

        $newsletter->refresh();
        $this->assertSame(NewsletterStatus::Draft, $newsletter->status);

        $batch->shouldNotHaveReceived('add');
    }

    public function test_it_adds_the_chunk_jobs_to_the_batch_in_groups(): void
    {
        [$newsletter, $contacts] = $this->queuedNewsletterWithContacts(SendNewsletterService::CHUNKS_PER_ADD + 1);
        $this->fakeTransport(chunkSize: 1);

        $adds = [];
        app(SendNewsletterService::class)->send($newsletter, $this->batchThatRecords($adds));

        $this->assertCount(2, $adds);
        $this->assertCount(SendNewsletterService::CHUNKS_PER_ADD, $adds[0]);
        $this->assertCount(1, $adds[1]);
        $this->assertSame($contacts->max('id'), $newsletter->refresh()->last_dispatched_contact_id);
    }

    public function test_a_retry_after_a_failure_in_the_middle_adds_no_chunk_for_contacts_that_an_earlier_group_added(): void
    {
        [$newsletter, $contacts] = $this->queuedNewsletterWithContacts(SendNewsletterService::CHUNKS_PER_ADD + 2);
        $transport = $this->fakeTransport(chunkSize: 1);

        $firstAttemptAdds = [];
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->once()->andReturnUsing(function (array $jobs) use (&$firstAttemptAdds, $batch) {
            $firstAttemptAdds[] = $jobs;

            return $batch;
        });
        $batch->shouldReceive('add')->once()->andThrow(new RuntimeException('The worker stopped the job'));

        try {
            app(SendNewsletterService::class)->send($newsletter, $batch);
            $this->fail('The first attempt did not stop.');
        } catch (RuntimeException) {
        }

        // The chunks of the first group wait in the queue, so no contact mail exists yet.
        $this->assertSame(0, ContactMail::query()->count());

        $retryAdds = [];
        app(SendNewsletterService::class)->send($newsletter, $this->batchThatRecords($retryAdds));

        $firstIds = $this->contactIdsOf($firstAttemptAdds);
        $retryIds = $this->contactIdsOf($retryAdds);

        $this->assertCount(SendNewsletterService::CHUNKS_PER_ADD, $firstIds);
        $this->assertSame($contacts->sortBy('id')->pluck('id')->slice(SendNewsletterService::CHUNKS_PER_ADD)->values()->all(), $retryIds);
        $this->assertSame([], array_intersect($firstIds, $retryIds));
        $this->assertSame(SendNewsletterService::CHUNKS_PER_ADD + 2, $newsletter->refresh()->total_contacts_reached);
        $this->assertSame('tpl-123', $newsletter->template_name);
        $transport->shouldHaveReceived('createTemplate')->once();
    }

    public function test_an_ab_send_splits_each_chunk_of_recipients_50_50(): void
    {
        [$newsletter] = $this->queuedNewsletterWithContacts(20, abTest: true);
        $this->fakeTransport(chunkSize: 2);

        $adds = [];
        app(SendNewsletterService::class)->send($newsletter, $this->batchThatRecords($adds));

        $this->assertCount(1, $adds);
        $jobs = collect($adds[0]);
        $this->assertCount(10, $jobs);
        $this->assertTrue($jobs->every(fn (SendNewsletterChunkJob $job): bool => count($job->contactIds) === 2));

        $groupA = $jobs->where('templateName', 'tpl-123')->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds);
        $groupB = $jobs->where('templateName', 'tpl-456')->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds);
        $this->assertCount(10, $groupA);
        $this->assertCount(10, $groupB);
        $this->assertSame([], $groupA->intersect($groupB)->all());
    }

    /**
     * @return array{Newsletter, Collection<int, Contact>}
     */
    private function queuedNewsletterWithContacts(int $count, bool $abTest = false): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contacts = Contact::factory()->count($count)->for($user)->create();

        $factory = Newsletter::factory()->for($user);
        $newsletter = ($abTest ? $factory->withAbTest() : $factory)->create(['status' => 'queued']);
        $newsletter->messages()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
        ]);

        return [$newsletter, $contacts];
    }

    private function fakeTransport(int $chunkSize): MockInterface
    {
        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')->andReturn('tpl-123', 'tpl-456');
        $transport->shouldReceive('getChunkSize')->andReturn($chunkSize);
        $this->app->instance(NewsletterTransport::class, $transport);

        return $transport;
    }

    /**
     * @param  array<int, array<int, SendNewsletterChunkJob>>  $adds
     */
    private function batchThatRecords(array &$adds): Batch
    {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('add')->andReturnUsing(function (array $jobs) use (&$adds, $batch) {
            $adds[] = $jobs;

            return $batch;
        });

        return $batch;
    }

    /**
     * @param  array<int, array<int, SendNewsletterChunkJob>>  $adds
     * @return array<int, int>
     */
    private function contactIdsOf(array $adds): array
    {
        return collect($adds)->flatten()->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)->values()->all();
    }
}
