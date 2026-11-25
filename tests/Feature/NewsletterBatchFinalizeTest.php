<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Events\NewsletterSent;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Services\SesPermissionChecker;
use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use Tests\TestCase;

/**
 * These tests run the real send batch on the database queue. Laravel then
 * calls the batch callbacks in the same order as in production.
 */
class NewsletterBatchFinalizeTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_a_newsletter_with_one_failed_chunk_ends_sent(): void
    {
        Event::fake([NewsletterSent::class]);
        [$newsletter, $contacts] = $this->newsletterWithContacts(3);
        $failingContact = $contacts[1];
        $this->fakeTransportThatFailsFor([$failingContact->id]);

        $this->sendThroughTheQueue($newsletter);

        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);
        $this->assertSame(NewsletterStatus::Sent, $newsletter->status);
        $this->assertNotNull($newsletter->sent_at);
        $this->assertSame(3, $newsletter->total_contacts_reached);
        $this->assertSame('failed', $this->contactMailOf($newsletter, $failingContact)->status);
        $this->assertSame('sent', $this->contactMailOf($newsletter, $contacts[0])->status);
        $this->assertSame('sent', $this->contactMailOf($newsletter, $contacts[2])->status);
        Event::assertDispatchedTimes(NewsletterSent::class, 1);
    }

    public function test_an_ab_newsletter_with_one_failed_chunk_ends_ab_testing(): void
    {
        Event::fake([NewsletterSent::class]);
        [$newsletter, $contacts] = $this->newsletterWithContacts(4, abTest: true);
        $this->fakeTransportThatFailsFor([$contacts[0]->id]);

        $this->sendThroughTheQueue($newsletter);

        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);
        $this->assertSame(NewsletterStatus::AbTesting, $newsletter->status);
        $this->assertSame(4, $newsletter->total_contacts_reached);
        Event::assertNotDispatched(NewsletterSent::class);
    }

    public function test_a_newsletter_with_every_chunk_failed_ends_failed(): void
    {
        Event::fake([NewsletterSent::class]);
        [$newsletter, $contacts] = $this->newsletterWithContacts(2);
        $this->fakeTransportThatFailsFor($contacts->pluck('id')->all());

        $this->sendThroughTheQueue($newsletter);

        $newsletter = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);
        $this->assertSame(NewsletterStatus::Failed, $newsletter->status);
        $this->assertNull($newsletter->sent_at);
        $this->assertSame(2, ContactMail::withoutGlobalScopes()->where('status', 'failed')->count());
        Event::assertNotDispatched(NewsletterSent::class);
    }

    /**
     * @return array{Newsletter, Collection<int, Contact>}
     */
    private function newsletterWithContacts(int $count, bool $abTest = false): array
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->production()->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $list = MailList::factory()->for($user)->create();
        $contacts = Contact::factory()->count($count)->for($user)->create();
        $contacts->each(fn (Contact $contact) => $contact->lists()->attach($list));

        $factory = Newsletter::factory()->for($user)->withListFilters([$list->id]);
        $newsletter = ($abTest ? $factory->withAbTest() : $factory)->create(['status' => 'queued', 'scheduled_at' => now()]);

        $newsletter->messages()->withoutGlobalScopes()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Hello</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
        ]);

        return [$newsletter, $contacts];
    }

    /**
     * Each chunk holds one contact. A chunk for a contact in the list throws.
     *
     * @param  array<int, int>  $failingContactIds
     */
    private function fakeTransportThatFailsFor(array $failingContactIds): void
    {
        $permissionChecker = Mockery::mock(SesPermissionChecker::class);
        $permissionChecker->shouldReceive('ensureAll');
        $this->app->instance(SesPermissionChecker::class, $permissionChecker);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturnFalse();
        $transport->shouldReceive('createTemplate')->andReturnNull();
        $transport->shouldReceive('getChunkSize')->andReturn(1);
        $transport->shouldReceive('sendBatch')->andReturnUsing(
            function (?string $templateName, string $fromEmail, ?string $fromName, Collection $contacts, Collection $contactMails) use ($failingContactIds): void {
                if ($contacts->whereIn('id', $failingContactIds)->isNotEmpty()) {
                    throw new RuntimeException('SES rejected the chunk');
                }

                $contactMails->each(fn (ContactMail $contactMail) => $contactMail->update(['status' => 'sent', 'sent_at' => now()]));
            },
        );
        $this->app->instance(NewsletterTransport::class, $transport);

        RateLimiter::for('ses-sending', fn (): Limit => Limit::none());
    }

    private function sendThroughTheQueue(Newsletter $newsletter): void
    {
        config(['queue.default' => 'database']);

        // The controller dispatches the job inside a request of the tenant.
        Tenant::run($newsletter->client_id, function () use ($newsletter): void {
            DispatchNewsletterBatchJob::dispatch($newsletter);
        });

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'newsletter',
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--memory' => 4096,
        ])->assertSuccessful();
    }

    private function contactMailOf(Newsletter $newsletter, Contact $contact): ContactMail
    {
        return ContactMail::withoutGlobalScopes()
            ->where('mailable_type', Newsletter::class)
            ->where('mailable_id', $newsletter->id)
            ->where('contact_id', $contact->id)
            ->sole();
    }
}
