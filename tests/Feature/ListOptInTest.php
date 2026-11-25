<?php

namespace Tests\Feature;

use App\Events\ContactSubscribed;
use App\Jobs\ProcessContactImportBatchJob;
use App\Jobs\SendOptInEmailJob;
use App\Mail\OptInMail;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\User;
use App\Services\EmailSendingGate;
use App\Services\UserMailerFactory;
use App\Support\Tenant;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class ListOptInTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    private function createOptInList(User $user, array $messageOverrides = []): MailList
    {
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        $identity = EmailIdentity::factory()->for($user)->create();

        $list->message()->create(array_merge([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'email_identity_id' => $identity->id,
            'subject' => 'Please confirm your subscription',
            'from_email' => $identity->email,
            'from_name' => $identity->name ?: $user->name,
            'html_content' => '<p><a href="{{opt_in_url}}">Confirm</a></p>',
            'text_content' => 'Confirm: {{opt_in_url}}',
            'markdown_content' => '',
        ], $messageOverrides));

        return $list;
    }

    /**
     * Mock UserMailerFactory to capture the sent OptInMail and verify user's mailer is used.
     *
     * @return OptInMail|null Reference to captured mail (populated after job runs)
     */
    /**
     * Create an EmailCredential for the user and mock MailManager to capture the sent OptInMail.
     */
    private function mockUserMailerFactory(User $user): stdClass
    {
        EmailCredential::factory()->for($user)->create();

        $capture = new stdClass;
        $capture->mail = null;

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')
            ->once()
            ->withArgs(function (array $config) use ($user): bool {
                return ($config['name'] ?? null) === 'ses-user-'.$user->id;
            })
            ->andReturn($mailer);

        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (OptInMail $mail) use ($capture): void {
                $capture->mail = $mail;
            });

        $this->app->instance(MailManager::class, $mailManager);

        return $capture;
    }

    /**
     * Create an EmailCredential for the user and mock MailManager so that every send throws.
     */
    private function mockFailingUserMailer(User $user, string $error): void
    {
        EmailCredential::factory()->for($user)->create();

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);

        $mailManager->shouldReceive('build')->andReturn($mailer);
        $mailer->shouldReceive('send')->andThrow(new RuntimeException($error));

        $this->app->instance(MailManager::class, $mailManager);
    }

    /**
     * @return array{0: Contact, 1: MailList, 2: ContactMail}
     */
    private function createOptInMail(User $user): array
    {
        $list = $this->createOptInList($user);
        $contact = Contact::factory()->for($user)->create();
        $contact->lists()->attach($list->id);

        $contactMail = ContactMail::factory()->create([
            'contact_id' => $contact->id,
            'client_id' => $contact->client_id,
            'mailable_type' => MailList::class,
            'mailable_id' => $list->id,
        ]);

        return [$contact, $list, $contactMail];
    }

    private function optedInAt(Contact $contact, MailList $list): ?string
    {
        return $contact->lists()->withoutGlobalScopes()->whereKey($list->id)->first()?->pivot->opted_in_at;
    }

    public function test_opt_in_link_shows_a_confirmation_page_and_changes_nothing(): void
    {
        Event::fake([ContactSubscribed::class]);
        [$contact, $list, $contactMail] = $this->createOptInMail($this->user());

        $this->get("/opt-in/{$contactMail->uuid}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OptIn')
                ->where('alreadyConfirmed', false)
                ->where('contact.email', $contact->email)
                ->where('list.name', $list->name)
                ->where('contactMailUuid', $contactMail->uuid)
            );

        $this->assertNull($this->optedInAt($contact, $list));
        Event::assertNotDispatched(ContactSubscribed::class);
    }

    public function test_opt_in_confirm_post_sets_opted_in_at_on_pivot(): void
    {
        [$contact, $list, $contactMail] = $this->createOptInMail($this->user());

        $this->post(route('contact-mails.opt-in', $contactMail->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('OptedIn')
                ->where('list.name', $list->name)
            );

        $this->assertNotNull($this->optedInAt($contact, $list));
    }

    public function test_opt_in_confirm_keeps_the_first_confirmation_date(): void
    {
        [$contact, $list, $contactMail] = $this->createOptInMail($this->user());

        $this->travelTo(now()->subDay());
        $this->post(route('contact-mails.opt-in', $contactMail->uuid))->assertOk();
        $this->travelBack();
        $firstConfirmation = $this->optedInAt($contact, $list);

        $this->post(route('contact-mails.opt-in', $contactMail->uuid))->assertOk();

        $this->assertSame($firstConfirmation, $this->optedInAt($contact, $list));
        $this->get("/opt-in/{$contactMail->uuid}")
            ->assertInertia(fn (Assert $page) => $page->where('alreadyConfirmed', true));
    }

    public function test_opt_in_confirm_does_not_add_a_contact_who_left_the_list(): void
    {
        Event::fake([ContactSubscribed::class]);
        [$contact, $list, $contactMail] = $this->createOptInMail($this->user());
        $contact->lists()->detach($list->id);

        $this->post(route('contact-mails.opt-in', $contactMail->uuid))->assertNotFound();
        $this->get("/opt-in/{$contactMail->uuid}")->assertNotFound();

        $this->assertNull(Tenant::run($contact->client_id, fn () => $contact->lists()->where('lists.id', $list->id)->first()));
        Event::assertNotDispatched(ContactSubscribed::class);
    }

    public function test_opt_in_works_when_a_user_of_another_client_is_logged_in(): void
    {
        [$contact, $list, $contactMail] = $this->createOptInMail($this->user());

        $this->actingAs($this->user())
            ->post(route('contact-mails.opt-in', $contactMail->uuid))
            ->assertOk();

        $this->assertNotNull($this->optedInAt($contact, $list));
    }

    public function test_opt_in_returns_404_when_the_contact_and_the_list_belong_to_different_clients(): void
    {
        [, $list, $contactMail] = $this->createOptInMail($this->user());
        $foreignContact = Contact::factory()->for($this->user())->create();
        $foreignContact->lists()->attach($list->id);
        $contactMail->forceFill(['contact_id' => $foreignContact->id])->save();

        $this->get("/opt-in/{$contactMail->uuid}")->assertNotFound();
        $this->post(route('contact-mails.opt-in', $contactMail->uuid))->assertNotFound();

        $this->assertNull($this->optedInAt($foreignContact, $list));
    }

    public function test_opt_in_link_returns_404_for_non_list_mailable(): void
    {
        $contactMail = ContactMail::factory()->create();

        $this->get("/opt-in/{$contactMail->uuid}")->assertNotFound();
        $this->post(route('contact-mails.opt-in', $contactMail->uuid))->assertNotFound();
    }

    public function test_opt_in_link_returns_404_for_invalid_uuid(): void
    {
        $this->get('/opt-in/nonexistent-uuid')->assertNotFound();
        $this->post('/opt-in/nonexistent-uuid')->assertNotFound();
    }

    public function test_opt_in_email_links_to_the_verified_link_domain(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->withLinkDomain('links.example.com')->create();
        $list = $this->createOptInList($user, ['email_identity_id' => $identity->id]);
        $contact = Contact::factory()->for($user)->create();

        $capture = $this->mockUserMailerFactory($user);

        (new SendOptInEmailJob($contact, $list))->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->firstOrFail();
        $expected = "https://links.example.com/opt-in/{$contactMail->uuid}";

        $this->assertSame($expected, $contactMail->opt_in_link);
        $this->assertStringContainsString('href="'.$expected.'"', Tenant::run($user->client_id, fn (): string => $capture->mail->render()));
    }

    public function test_api_dispatches_opt_in_job_when_enabled(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $list = $this->createOptInList($user);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'email' => 'optin@example.com',
        ]);

        $response->assertCreated();

        Queue::assertPushed(SendOptInEmailJob::class, function (SendOptInEmailJob $job) use ($list) {
            return $job->mailList->id === $list->id
                && $job->contact->email === 'optin@example.com';
        });

        $contact = Contact::where('email', 'optin@example.com')->first();
        $this->assertNull(
            $contact->lists()->where('lists.id', $list->id)->first()->pivot->opted_in_at
        );
    }

    public function test_api_sets_opted_in_at_when_opt_in_disabled(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => false]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/lists/{$list->id}/contacts", [
            'email' => 'nooptin@example.com',
        ]);

        $response->assertCreated();

        Queue::assertNotPushed(SendOptInEmailJob::class);

        $contact = Contact::where('email', 'nooptin@example.com')->first();
        $this->assertNotNull(
            $contact->lists()->where('lists.id', $list->id)->first()->pivot->opted_in_at
        );
    }

    public function test_an_import_into_a_list_without_opt_in_sets_opted_in_at(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => false]);

        $contactImport = ContactImport::factory()
            ->processing()
            ->for($user)
            ->for($list, 'mailList')
            ->create(['total_count' => 1, 'processed_count' => 0, 'skipped_count' => 0]);

        $rows = [[
            'email' => 'imported@example.com',
            'name' => 'Imported User',
            'tags' => null,
            'attributes' => [],
        ]];

        [$job] = (new ProcessContactImportBatchJob($user, $list, $contactImport, $rows))->withFakeBatch();
        app()->call([$job, 'handle']);

        Tenant::run($user->client_id, function () use ($list): void {
            $contact = Contact::where('email', 'imported@example.com')->first();
            $this->assertNotNull($contact);
            $this->assertNotNull(
                $contact->lists()->where('lists.id', $list->id)->first()->pivot->opted_in_at
            );
        });
    }

    public function test_user_can_view_list_edit_page(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'My List']);

        $response = $this->actingAs($user)->get(route('lists.edit', $list));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('lists/Edit')
            ->has('list', fn (Assert $listAssert) => $listAssert
                ->where('name', 'My List')
                ->has('opt_in_enabled')
                ->etc()
            )
            ->has('emailIdentities')
        );
    }

    public function test_user_can_update_list_with_opt_in_settings(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['name' => 'Old Name']);
        $identity = EmailIdentity::factory()->for($user)->create();

        $response = $this->actingAs($user)->put(route('lists.update', $list), [
            'name' => 'New Name',
            'opt_in_enabled' => true,
            'opt_in_subject' => 'Confirm your subscription',
            'opt_in_html_content' => '<p><a href="{{opt_in_url}}">Confirm</a></p>',
            'opt_in_email_identity_id' => $identity->id,
        ]);

        $response->assertRedirect(route('lists.show', $list));

        $list->refresh();
        $this->assertSame('New Name', $list->name);
        $this->assertTrue($list->opt_in_enabled);

        $message = $list->message;
        $this->assertNotNull($message);
        $this->assertSame('Confirm your subscription', $message->subject);
        $this->assertSame('<p><a href="{{opt_in_url}}">Confirm</a></p>', $message->html_content);
        $this->assertSame($identity->id, $message->email_identity_id);
        $this->assertSame($identity->email, $message->from_email);
    }

    public function test_opt_in_identity_of_another_client_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $otherIdentity = EmailIdentity::factory()->for(User::factory()->create())->create();

        $this->actingAs($user)->put(route('lists.update', $list), [
            'name' => 'Test List',
            'opt_in_enabled' => true,
            'opt_in_subject' => 'Confirm your subscription',
            'opt_in_html_content' => '<p><a href="{{opt_in_url}}">Confirm</a></p>',
            'opt_in_email_identity_id' => $otherIdentity->id,
        ])->assertSessionHasErrors('opt_in_email_identity_id');

        $this->assertNull($list->fresh()->message);
    }

    public function test_opt_in_fields_required_when_opt_in_enabled(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)->put(route('lists.update', $list), [
            'name' => 'Test List',
            'opt_in_enabled' => true,
            'opt_in_subject' => '',
            'opt_in_html_content' => '',
            'opt_in_email_identity_id' => '',
        ]);

        $response->assertSessionHasErrors(['opt_in_subject', 'opt_in_html_content', 'opt_in_email_identity_id']);
    }

    public function test_opt_in_fields_not_required_when_opt_in_disabled(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();

        $response = $this->actingAs($user)->put(route('lists.update', $list), [
            'name' => 'Test List',
            'opt_in_enabled' => false,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('lists.show', $list));
    }

    public function test_user_cannot_edit_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $list = MailList::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->get(route('lists.edit', $list));

        $response->assertNotFound();
    }

    public function test_user_cannot_update_other_users_list(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $list = MailList::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)->put(route('lists.update', $list), [
            'name' => 'Hacked',
        ]);

        $response->assertNotFound();
    }

    public function test_send_opt_in_email_job_creates_contact_mail_and_sends_via_user_mailer(): void
    {
        $user = User::factory()->create();
        $list = $this->createOptInList($user, [
            'subject' => 'Please confirm',
        ]);
        $contact = Contact::factory()->for($user)->create(['email' => 'test@example.com']);

        $capture = $this->mockUserMailerFactory($user);

        $job = new SendOptInEmailJob($contact, $list);
        $job->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', MailList::class)
            ->where('mailable_id', $list->id)
            ->first();

        $this->assertNotNull($contactMail);
        $this->assertSame('Please confirm', $contactMail->subject);
        $this->assertSame('sent', $contactMail->status);
        $this->assertNotNull($contactMail->sent_at);
        $this->assertSame(Tenant::run($user->client_id, fn (): int => $list->message->id), $contactMail->message_id);

        $this->assertNotNull($capture->mail);
        $this->assertInstanceOf(OptInMail::class, $capture->mail);
        $this->assertTrue($capture->mail->contactMail->is($contactMail));
    }

    public function test_send_opt_in_email_job_does_not_mark_the_mail_sent_when_the_send_throws(): void
    {
        $user = User::factory()->create();
        $list = $this->createOptInList($user);
        $contact = Contact::factory()->for($user)->create();

        $this->mockFailingUserMailer($user, 'SES rejected the message');

        try {
            (new SendOptInEmailJob($contact, $list))->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));
            $this->fail('The job must throw when the send throws.');
        } catch (RuntimeException $e) {
            $this->assertSame('SES rejected the message', $e->getMessage());
        }

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame('pending', $contactMail->status);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_send_opt_in_email_job_marks_the_mail_failed_with_the_reason_after_the_final_failure(): void
    {
        $user = User::factory()->create();
        $list = $this->createOptInList($user);
        $contact = Contact::factory()->for($user)->create();

        $this->mockFailingUserMailer($user, 'SES rejected the message');

        try {
            SendOptInEmailJob::dispatch($contact, $list);
            $this->fail('The sync queue must rethrow the send error.');
        } catch (RuntimeException) {
        }

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->firstOrFail();

        $this->assertSame('failed', $contactMail->status);
        $this->assertSame('SES rejected the message', $contactMail->failure_reason);
        $this->assertNull($contactMail->sent_at);
    }

    public function test_send_opt_in_email_job_failure_does_not_change_a_sent_mail(): void
    {
        $user = User::factory()->create();
        [$contact, $list, $contactMail] = $this->createOptInMail($user);
        $contactMail->forceFill(['status' => 'sent', 'sent_at' => now()])->save();

        (new SendOptInEmailJob($contact, $list))->failed(new RuntimeException('Timed out after the send'));

        $this->assertSame('sent', $contactMail->refresh()->status);
        $this->assertNull($contactMail->failure_reason);
    }

    public function test_send_opt_in_email_job_runs_on_the_sequences_queue_and_fails_on_the_first_exception(): void
    {
        $user = User::factory()->create();
        $list = $this->createOptInList($user);
        $contact = Contact::factory()->for($user)->create();

        $job = new SendOptInEmailJob($contact, $list);

        $this->assertSame('sequences', $job->queue);
        $this->assertSame(1, $job->maxExceptions);
    }

    public function test_send_opt_in_email_job_skips_when_no_message(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        $contact = Contact::factory()->for($user)->create();

        $job = new SendOptInEmailJob($contact, $list);
        $job->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));

        $this->assertDatabaseMissing('contact_mails', [
            'contact_id' => $contact->id,
            'mailable_type' => MailList::class,
        ]);
    }

    public function test_opt_in_email_replaces_template_variables_in_subject_and_content(): void
    {
        $user = User::factory()->create();
        $list = $this->createOptInList($user, [
            'subject' => 'Hi {{ name }}, please confirm',
            'html_content' => '<p>Hello {{ name }} ({{ email }}), click <a href="{{opt_in_url}}">here</a> to confirm.</p>',
            'text_content' => 'Hello {{ name }} ({{ email }}), confirm: {{opt_in_url}}',
        ]);
        $contact = Contact::factory()->for($user)->create([
            'email' => 'jane@example.com',
            'name' => 'Jane Doe',
        ]);

        $capture = $this->mockUserMailerFactory($user);

        $job = new SendOptInEmailJob($contact, $list);
        $job->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));

        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', MailList::class)
            ->first();

        $this->assertNotNull($contactMail);
        $this->assertArrayHasKey('name', $contactMail->template_data);
        $this->assertSame('Jane Doe', $contactMail->template_data['name']);
        $this->assertArrayHasKey('email', $contactMail->template_data);
        $this->assertSame('jane@example.com', $contactMail->template_data['email']);

        $this->assertNotNull($capture->mail);
        Tenant::run($user->client_id, fn () => $capture->mail->assertHasSubject('Hi Jane Doe, please confirm'));
    }

    public function test_opt_in_email_replaces_extra_attributes_as_variables(): void
    {
        $user = User::factory()->create();
        $list = $this->createOptInList($user, [
            'subject' => 'Welcome {{ company }}',
            'html_content' => '<p>{{ company }} - <a href="{{opt_in_url}}">Confirm</a></p>',
            'text_content' => '{{ company }} - Confirm: {{opt_in_url}}',
        ]);
        $contact = Contact::factory()->for($user)->create([
            'email' => 'bob@example.com',
            'extra_attributes' => ['company' => 'Acme Inc'],
        ]);

        $capture = $this->mockUserMailerFactory($user);

        $job = new SendOptInEmailJob($contact, $list);
        $job->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->first();

        $this->assertSame('Acme Inc', $contactMail->template_data['company']);

        $this->assertNotNull($capture->mail);
        Tenant::run($user->client_id, fn () => $capture->mail->assertHasSubject('Welcome Acme Inc'));
    }
}
