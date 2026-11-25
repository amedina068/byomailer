<?php

namespace Tests\Feature;

use App\Jobs\SendNewsletterChunkJob;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Support\Tenant;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class ContactAttributeGlobalDefaultSendTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Newsletter, 2: Contact}
     */
    private function makeNewsletterSetup(string $html, array $extraAttributes = []): array
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => $extraAttributes,
        ]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tpl-123']);
        $newsletter->message()->withoutGlobalScopes()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Hi',
            'html_content' => $html,
        ]);

        return [$user, $newsletter, $contact];
    }

    private function runChunkJob(User $user, Newsletter $newsletter, Contact $contact): ContactMail
    {
        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();
        $message = $newsletter->message()->withoutGlobalScopes()->firstOrFail();

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $message->from_email,
            fromName: 'Test Sender',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $message,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        return ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->firstOrFail();
    }

    public function test_chunk_job_resolves_own_client_default_when_two_clients_share_attribute_name(): void
    {
        [$userA, $newsletterA, $contactA] = $this->makeNewsletterSetup('<p>{{ company }}</p>');
        $userB = User::factory()->create();

        ContactAttribute::factory()->withDefault('Client A Co')->create([
            'user_id' => $userA->id,
            'client_id' => $userA->client_id,
            'name' => 'company',
        ]);
        ContactAttribute::factory()->withDefault('Client B Co')->create([
            'user_id' => $userB->id,
            'client_id' => $userB->client_id,
            'name' => 'company',
        ]);

        $contactMail = $this->runChunkJob($userA, $newsletterA, $contactA);

        $this->assertSame('Client A Co', $contactMail->template_data['company']);
    }

    public function test_chunk_job_resolves_empty_when_attribute_belongs_to_another_client_only(): void
    {
        [$userA, $newsletterA, $contactA] = $this->makeNewsletterSetup('<p>{{ company }}</p>');
        $userB = User::factory()->create();

        ContactAttribute::factory()->withDefault('Client B Co')->create([
            'user_id' => $userB->id,
            'client_id' => $userB->client_id,
            'name' => 'company',
        ]);

        $contactMail = $this->runChunkJob($userA, $newsletterA, $contactA);

        $this->assertSame('', $contactMail->template_data['company']);
    }

    public function test_chunk_job_keeps_real_unsubscribe_link_over_same_named_global_default(): void
    {
        [$user, $newsletter, $contact] = $this->makeNewsletterSetup(
            '<p><a href="{{ unsubscribe_url }}">bye</a></p>',
        );

        ContactAttribute::factory()->withDefault('https://evil.example')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'unsubscribe_url',
        ]);

        $contactMail = $this->runChunkJob($user, $newsletter, $contact);

        $this->assertNotSame('https://evil.example', $contactMail->template_data['unsubscribe_url']);
        $this->assertStringContainsString('/unsubscribe/', $contactMail->template_data['unsubscribe_url']);
    }

    public function test_chunk_job_resolves_empty_for_case_mismatched_attribute_name(): void
    {
        [$user, $newsletter, $contact] = $this->makeNewsletterSetup('<p>{{ FirstName }}</p>');

        ContactAttribute::factory()->withDefault('Ada')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'firstname',
        ]);

        $contactMail = $this->runChunkJob($user, $newsletter, $contact);

        $this->assertSame('', $contactMail->template_data['FirstName']);
    }

    public function test_ab_variant_jobs_only_resolve_variables_detected_in_their_own_message(): void
    {
        [$user, $newsletter, $contact] = $this->makeNewsletterSetup('<p>Variant A</p>');

        ContactAttribute::factory()->withDefault('Global Co')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'company',
        ]);

        $identity = EmailIdentity::query()->withoutGlobalScopes()->firstOrFail();
        $messageB = $newsletter->message()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'email_identity_id' => $identity->id,
            'from_email' => $newsletter->message()->withoutGlobalScopes()->value('from_email'),
            'from_name' => 'Test Sender',
            'subject' => 'Hi B',
            'markdown_content' => 'Variant B {{ company }}',
            'html_content' => '<p>Variant B {{ company }}</p>',
            'text_content' => 'Variant B',
            'variant' => 'b',
        ]);

        $contactMailA = $this->runChunkJob($user, $newsletter, $contact);
        $this->assertArrayNotHasKey('company', $contactMailA->template_data);

        $contactMailA->delete();

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        $jobB = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $messageB->from_email,
            fromName: 'Test Sender',
            contactIds: [$contact->id],
            templateName: 'tpl-123',
            message: $messageB,
            tenantName: 'byomailer',
        );
        $jobB->handle($transport);

        $contactMailB = ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->firstOrFail();

        $this->assertSame('Global Co', $contactMailB->template_data['company']);
    }

    public function test_sequence_command_resolves_only_own_client_global_default(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        ContactAttribute::factory()->withDefault('Client A Co')->create([
            'user_id' => $userA->id,
            'client_id' => $userA->client_id,
            'name' => 'company',
        ]);
        ContactAttribute::factory()->withDefault('Client B Co')->create([
            'user_id' => $userB->id,
            'client_id' => $userB->client_id,
            'name' => 'company',
        ]);

        $contactMail = $this->runSequenceStepSend($userA, '<p>{{ company }}</p>');

        $this->assertSame('Client A Co', $contactMail->template_data['company']);
    }

    public function test_sequence_command_keeps_real_unsubscribe_link_over_same_named_global_default(): void
    {
        $user = User::factory()->create();

        ContactAttribute::factory()->withDefault('https://evil.example')->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => 'unsubscribe_url',
        ]);

        $contactMail = $this->runSequenceStepSend($user, '<p><a href="{{ unsubscribe_url }}">bye</a></p>');

        $this->assertNotSame('https://evil.example', $contactMail->template_data['unsubscribe_url']);
        $this->assertStringContainsString('/unsubscribe/', $contactMail->template_data['unsubscribe_url']);
    }

    private function runSequenceStepSend(User $user, string $html): ContactMail
    {
        $this->withoutSesSendingLimit();

        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLE123456789',
            'secret_key' => 'example-secret-key-1234567890',
            'region' => 'ap-south-1',
        ]);

        $contact = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create([
            'delay_days' => 0,
            'status' => 'live',
        ]);
        $step->message()->withoutGlobalScopes()->update([
            'html_content' => $html,
            'text_content' => 'Plain copy',
        ]);
        $step->refresh();

        Tenant::run($user->client_id, fn () => $contact->syncSequences([$sequence->id], true));

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->andReturn($mailer);
        $mailer->shouldReceive('send')->andReturnNull();
        $this->app->instance(MailManager::class, $mailManager);

        $this->artisan('contact-mails:send-due-sequence-steps')->assertExitCode(0);

        Carbon::setTestNow();

        return ContactMail::withoutGlobalScopes()
            ->where('contact_id', $contact->id)
            ->where('mailable_type', SequenceStep::class)
            ->firstOrFail();
    }
}
