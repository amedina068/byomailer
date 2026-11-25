<?php

namespace Tests\Feature;

use App\Jobs\BackfillTopicSubscribersJob;
use App\Jobs\ProcessContactImportBatchJob;
use App\Jobs\SendOptInEmailJob;
use App\Models\Automation;
use App\Models\BlacklistedEmail;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Services\ContactFilterService;
use App\Services\EmailSendingGate;
use App\Services\UserMailerFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * A contact on an opt-in list counts as a member only after it confirms. Each
 * path that adds a contact to an opt-in list sends the opt-in email.
 */
class DoubleOptInTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private User $user;

    private MailList $optInList;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->optInList = MailList::factory()->for($this->user)->create(['opt_in_enabled' => true]);
    }

    public function test_a_list_filter_skips_the_unconfirmed_contacts_of_an_opt_in_list(): void
    {
        $this->actingAsTenant($this->user->client_id);
        $plainList = MailList::factory()->for($this->user)->create();
        $confirmed = Contact::factory()->for($this->user)->create();
        $confirmed->lists()->attach($this->optInList, ['opted_in_at' => now()]);
        $unconfirmed = Contact::factory()->for($this->user)->create();
        $unconfirmed->lists()->attach($this->optInList);
        $plainMember = Contact::factory()->for($this->user)->create();
        $plainMember->lists()->attach($plainList);

        $service = app(ContactFilterService::class);
        $any = [['connector' => 'and', 'category' => 'lists', 'mode' => 'any', 'values' => [$this->optInList->id, $plainList->id]]];
        $all = [['connector' => 'and', 'category' => 'lists', 'mode' => 'all', 'values' => [$this->optInList->id]]];

        $this->assertTrue($service->contactMatchesFilters($confirmed, $any));
        $this->assertTrue($service->contactMatchesFilters($plainMember, $any));
        $this->assertFalse($service->contactMatchesFilters($unconfirmed, $any));
        $this->assertTrue($service->contactMatchesFilters($confirmed, $all));
        $this->assertFalse($service->contactMatchesFilters($unconfirmed, $all));
    }

    public function test_the_dashboard_list_sync_sends_the_opt_in_email_and_waits_for_the_confirmation(): void
    {
        Queue::fake();
        $contact = Contact::factory()->for($this->user)->create();
        Topic::factory()->for($this->user)->create(['list_id' => $this->optInList->id]);

        $this->actingAs($this->user)->post(route('contacts.lists.sync', $contact), [
            'list_ids' => [$this->optInList->id],
        ])->assertRedirect();

        $this->assertOptInRequested($contact);
        $this->assertSame(0, $contact->topics()->count());
    }

    public function test_the_automation_add_to_list_action_sends_the_opt_in_email(): void
    {
        Queue::fake([SendOptInEmailJob::class]);
        $this->actingAsTenant($this->user->client_id);
        $tag = Tag::factory()->for($this->user)->create();
        Automation::factory()->for($this->user)->create([
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_list', 'value' => $this->optInList->id]],
        ]);
        $contact = Contact::factory()->for($this->user)->create();

        $contact->syncTags([$tag->id], true);

        $this->assertOptInRequested($contact);
    }

    public function test_the_api_sends_the_opt_in_email_one_time_for_a_new_member(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson("/api/v1/lists/{$this->optInList->id}/contacts", ['email' => 'new@example.com'])->assertCreated();
        $this->postJson("/api/v1/lists/{$this->optInList->id}/contacts", ['email' => 'new@example.com', 'plan' => 'pro'])->assertOk();

        $this->assertOptInRequested(Contact::query()->where('email', 'new@example.com')->sole());
        Queue::assertPushed(SendOptInEmailJob::class, 1);
    }

    public function test_an_import_into_an_opt_in_list_sends_the_opt_in_email(): void
    {
        Queue::fake([SendOptInEmailJob::class]);
        $contactImport = ContactImport::factory()->processing()->for($this->user)->for($this->optInList, 'mailList')
            ->create(['total_count' => 1, 'processed_count' => 0, 'skipped_count' => 0]);
        $rows = [['email' => 'imported@example.com', 'name' => null, 'tags' => null, 'attributes' => []]];

        [$job] = (new ProcessContactImportBatchJob($this->user, $this->optInList, $contactImport, $rows))->withFakeBatch();
        app()->call([$job, 'handle']);

        Tenant::run($this->user->client_id, fn () => $this->assertOptInRequested(Contact::query()->where('email', 'imported@example.com')->sole()));
    }

    public function test_the_confirmation_subscribes_the_contact_to_the_topics_of_the_list(): void
    {
        $topic = Topic::factory()->for($this->user)->create(['list_id' => $this->optInList->id]);
        $contact = Contact::factory()->for($this->user)->create();
        $contact->lists()->attach($this->optInList);
        $contactMail = ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'client_id' => $this->user->client_id,
            'mailable_type' => MailList::class,
            'mailable_id' => $this->optInList->id,
        ]);

        $this->post(route('contact-mails.opt-in', $contactMail->uuid))->assertOk();

        Tenant::run($this->user->client_id, function () use ($contact, $topic): void {
            $this->assertNotNull($contact->lists()->sole()->pivot->opted_in_at);
            $this->assertTrue($contact->topics()->whereKey($topic->id)->exists());
        });
    }

    public function test_a_new_topic_skips_the_unconfirmed_contacts_of_an_opt_in_list(): void
    {
        $confirmed = Contact::factory()->for($this->user)->create();
        $confirmed->lists()->attach($this->optInList, ['opted_in_at' => now()]);
        $unconfirmed = Contact::factory()->for($this->user)->create();
        $unconfirmed->lists()->attach($this->optInList);
        $topic = Topic::factory()->for($this->user)->create(['list_id' => $this->optInList->id]);

        (new BackfillTopicSubscribersJob($topic->id))->handle();

        $this->assertSame([$confirmed->id], Tenant::run($this->user->client_id, fn (): array => $topic->contacts()->pluck('contacts.id')->all()));
    }

    public function test_the_opt_in_job_does_not_email_an_unsubscribed_or_confirmed_contact(): void
    {
        $identity = EmailIdentity::factory()->for($this->user)->create();
        $this->optInList->message()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->user->client_id,
            'email_identity_id' => $identity->id,
            'subject' => 'Please confirm',
            'from_email' => $identity->email,
            'html_content' => '<p><a href="{{ opt_in_url }}">Confirm</a></p>',
            'text_content' => 'Confirm: {{ opt_in_url }}',
            'markdown_content' => '',
        ]);
        $mailers = Mockery::mock(UserMailerFactory::class);
        $mailers->shouldNotReceive('mailerFor');
        $unsubscribed = Contact::factory()->for($this->user)->create(['unsubscribed_at' => now()]);
        $unsubscribed->lists()->attach($this->optInList);
        $confirmed = Contact::factory()->for($this->user)->create();
        $confirmed->lists()->attach($this->optInList, ['opted_in_at' => now()]);

        (new SendOptInEmailJob($unsubscribed, $this->optInList))->handle($mailers, app(EmailSendingGate::class));
        (new SendOptInEmailJob($confirmed, $this->optInList))->handle($mailers, app(EmailSendingGate::class));

        $this->assertSame(0, ContactMail::withoutGlobalScopes()->where('mailable_type', MailList::class)->count());
    }

    public function test_the_opt_in_job_does_not_email_a_blacklisted_address(): void
    {
        $this->createOptInMessage();
        $mailers = Mockery::mock(UserMailerFactory::class);
        $mailers->shouldNotReceive('mailerFor');
        $contact = Contact::factory()->for($this->user)->create(['email' => 'Blocked@Example.com']);
        $contact->lists()->attach($this->optInList);
        BlacklistedEmail::factory()->for($this->user)->create(['email' => 'blocked@example.com']);

        (new SendOptInEmailJob($contact, $this->optInList))->handle($mailers, app(EmailSendingGate::class));

        $this->assertSame(0, ContactMail::withoutGlobalScopes()->where('mailable_type', MailList::class)->count());
    }

    public function test_the_opt_in_job_records_a_failed_mail_when_the_sending_gate_blocks(): void
    {
        $this->createOptInMessage();
        $mailers = Mockery::mock(UserMailerFactory::class);
        $mailers->shouldNotReceive('mailerFor');
        $contact = Contact::factory()->for($this->user)->create();
        $contact->lists()->attach($this->optInList);
        $this->user->forceFill(['deactivated_at' => now()])->save();

        (new SendOptInEmailJob($contact, $this->optInList->fresh()))->handle($mailers, app(EmailSendingGate::class));

        $mail = ContactMail::withoutGlobalScopes()->where('mailable_type', MailList::class)->sole();
        $this->assertSame('failed', $mail->status);
        $this->assertSame(EmailSendingGate::USER_DEACTIVATED_REASON, $mail->failure_reason);
        $this->assertNull($mail->sent_at);
    }

    private function createOptInMessage(): void
    {
        $identity = EmailIdentity::factory()->for($this->user)->create();
        $this->optInList->message()->create([
            'user_id' => $this->user->id,
            'client_id' => $this->user->client_id,
            'email_identity_id' => $identity->id,
            'subject' => 'Please confirm',
            'from_email' => $identity->email,
            'html_content' => '<p><a href="{{ opt_in_url }}">Confirm</a></p>',
            'text_content' => 'Confirm: {{ opt_in_url }}',
            'markdown_content' => '',
        ]);
    }

    private function assertOptInRequested(Contact $contact): void
    {
        Queue::assertPushed(SendOptInEmailJob::class, fn (SendOptInEmailJob $job): bool => $job->contact->is($contact)
            && $job->mailList->is($this->optInList));

        $this->assertNull($contact->lists()->whereKey($this->optInList->id)->sole()->pivot->opted_in_at);
    }
}
