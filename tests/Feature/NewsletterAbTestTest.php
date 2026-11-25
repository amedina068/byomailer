<?php

namespace Tests\Feature;

use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Jobs\SendNewsletterChunkJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Services\SendNewsletterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class NewsletterAbTestTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_newsletter_content_step_persists_ab_test_data(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $response = $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'AB Newsletter',
            'subject' => 'Variant A Subject',
            'html_content' => '<p>Variant A Body</p>',
            'ab_test_enabled' => true,
            'subject_b' => 'Variant B Subject',
            'html_content_b' => '<p>Variant B Body</p>',
        ]);

        $response->assertRedirect(route('newsletters.variables.show', $newsletter));

        $newsletter->refresh();
        $this->assertTrue($newsletter->ab_test_enabled);

        $messageA = $newsletter->messageA;
        $messageB = $newsletter->messageB;

        $this->assertNotNull($messageA);
        $this->assertSame(AbVariant::A, $messageA->variant);
        $this->assertSame('Variant A Subject', $messageA->subject);

        $this->assertNotNull($messageB);
        $this->assertSame(AbVariant::B, $messageB->variant);
        $this->assertSame('Variant B Subject', $messageB->subject);
        $this->assertSame('<p>Variant B Body</p>', $messageB->html_content);
    }

    public function test_disabling_ab_test_deletes_variant_b(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create();

        $this->assertNotNull($newsletter->messageB);

        $response = $this->actingAs($user)->patch(route('newsletters.content.update', $newsletter), [
            'title' => 'Regular Newsletter',
            'subject' => 'Subject A',
            'html_content' => '<p>Body A</p>',
            'ab_test_enabled' => false,
        ]);

        $response->assertRedirect();

        $newsletter->refresh();
        $this->assertFalse($newsletter->ab_test_enabled);
        $this->assertNull($newsletter->messageB()->first());
        $this->assertNull($newsletter->messageA()->first()?->variant); // variant should be null now
    }

    public function test_newsletter_resource_includes_ab_fields(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create(['status' => 'sent']);

        $response = $this->actingAs($user)->get(route('newsletters.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Show')
            ->where('newsletter.ab_test_enabled', true)
            ->where('newsletter.ab_test_winner_variant', null)
            ->has('newsletter.subject_b')
            ->has('newsletter.html_content_b')
            ->has('newsletter.ab_metrics')
        );
    }

    public function test_show_page_sends_the_winner_and_the_variant_metrics_as_strings(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create([
            'status' => 'sent',
            'ab_test_winner_variant' => AbVariant::B,
        ]);

        $this->actingAs($user)->get(route('newsletters.show', $newsletter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('newsletter.ab_test_winner_variant', 'b')
                ->has('newsletter.ab_metrics.a')
                ->has('newsletter.ab_metrics.b')
            );
    }

    public function test_pick_ab_winner_sets_winner_and_status(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create(['status' => 'ab_testing']);

        $response = $this->actingAs($user)->post(route('newsletters.ab-winner', $newsletter), [
            'winner_variant' => 'b',
        ]);

        $response->assertRedirect();

        $newsletter->refresh();
        $this->assertSame(AbVariant::B, $newsletter->ab_test_winner_variant);
        $this->assertSame(NewsletterStatus::Sent, $newsletter->status);
        $this->assertNotNull($newsletter->sent_at);
    }

    public function test_pick_ab_winner_requires_ab_testing_status(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create(['status' => 'queued']);

        $response = $this->actingAs($user)->post(route('newsletters.ab-winner', $newsletter), [
            'winner_variant' => 'a',
        ]);

        $response->assertRedirect()->assertSessionHasErrors([
            'status' => 'You can pick a winner only while the A/B test runs. This newsletter is queued.',
        ]);
        $this->assertSame(NewsletterStatus::Queued, $newsletter->fresh()->status);
        $this->assertNull($newsletter->fresh()->ab_test_winner_variant);
    }

    public function test_pick_ab_winner_requires_a_b_variant(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'ab_testing', 'ab_test_enabled' => true]);

        $response = $this->actingAs($user)->post(route('newsletters.ab-winner', $newsletter), [
            'winner_variant' => 'a',
        ]);

        $response->assertRedirect()->assertSessionHasErrors('ab_test_enabled');
        $this->assertSame(NewsletterStatus::AbTesting, $newsletter->fresh()->status);
        $this->assertNull($newsletter->fresh()->ab_test_winner_variant);
    }

    public function test_pick_ab_winner_validates_variant(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create(['status' => 'ab_testing']);

        $response = $this->actingAs($user)->post(route('newsletters.ab-winner', $newsletter), [
            'winner_variant' => 'c',
        ]);

        $response->assertSessionHasErrors('winner_variant');
    }

    public function test_pick_ab_winner_requires_authentication(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($user)->create(['status' => 'ab_testing']);

        $response = $this->post(route('newsletters.ab-winner', $newsletter), [
            'winner_variant' => 'a',
        ]);

        $response->assertRedirect(route('login'));
    }

    public function test_pick_ab_winner_enforces_newsletter_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $newsletter = Newsletter::factory()->withAbTest()->for($owner)->create(['status' => 'ab_testing']);

        $response = $this->actingAs($other)->post(route('newsletters.ab-winner', $newsletter), [
            'winner_variant' => 'a',
        ]);

        $response->assertStatus(404);
    }

    public function test_ab_send_dispatches_jobs_for_both_variants(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contacts = Contact::factory()->count(10)->for($user)->create();

        $newsletter = Newsletter::factory()
            ->withAbTest()
            ->for($user)
            ->create(['status' => 'queued', 'scheduled_at' => now()]);

        $newsletter->messageA()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);
        $newsletter->messageB()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $templateNameA = 'tmpl-a-'.$newsletter->id;
        $templateNameB = 'tmpl-b-'.$newsletter->id;

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')
            ->twice()
            ->andReturn($templateNameA, $templateNameB);
        $transport->shouldReceive('getChunkSize')->andReturn(14);
        $this->app->instance(NewsletterTransport::class, $transport);

        Bus::fake([SendNewsletterChunkJob::class]);

        $batch = Bus::batch([])->name("newsletter-{$newsletter->id}")->onQueue('newsletter')->dispatch();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $jobs = $batch->added;

        $this->assertNotEmpty($jobs);

        $templateNames = collect($jobs)->map(fn (SendNewsletterChunkJob $job) => $job->templateName)->unique()->values()->all();
        $this->assertCount(2, $templateNames);
        $this->assertContains($templateNameA, $templateNames);
        $this->assertContains($templateNameB, $templateNames);

        $allContactIds = collect($jobs)->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)->all();
        $this->assertCount(10, $allContactIds);
    }

    public function test_ab_send_splits_contacts_50_50(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->withTenant()->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contacts = Contact::factory()->count(20)->for($user)->create();

        $newsletter = Newsletter::factory()
            ->withAbTest()
            ->for($user)
            ->create(['status' => 'queued']);

        $newsletter->messageA()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);
        $newsletter->messageB()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $templateNameA = 'tmpl-a';
        $templateNameB = 'tmpl-b';

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('usesSesApi')->andReturn(true);
        $transport->shouldReceive('createTemplate')
            ->twice()
            ->andReturn($templateNameA, $templateNameB);
        $transport->shouldReceive('getChunkSize')->andReturn(100);
        $this->app->instance(NewsletterTransport::class, $transport);

        Bus::fake([SendNewsletterChunkJob::class]);

        $batch = Bus::batch([])->name("newsletter-{$newsletter->id}")->onQueue('newsletter')->dispatch();

        app(SendNewsletterService::class)->send($newsletter, $batch);

        $jobs = $batch->added;

        $groupAIds = collect($jobs)
            ->filter(fn (SendNewsletterChunkJob $job) => $job->templateName === $templateNameA)
            ->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)
            ->all();

        $groupBIds = collect($jobs)
            ->filter(fn (SendNewsletterChunkJob $job) => $job->templateName === $templateNameB)
            ->flatMap(fn (SendNewsletterChunkJob $job) => $job->contactIds)
            ->all();

        $this->assertCount(10, $groupAIds);
        $this->assertCount(10, $groupBIds);

        $this->assertEmpty(array_intersect($groupAIds, $groupBIds));
    }

    public function test_contact_mails_have_correct_message_id_set(): void
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $contact = Contact::factory()->for($user)->create();

        $newsletter = Newsletter::factory()
            ->withAbTest()
            ->for($user)
            ->create(['status' => 'queued', 'template_name' => 'tmpl-a']);

        $newsletter->messageA()->withoutGlobalScopes()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Sender',
        ]);

        $messageA = $newsletter->messageA()->withoutGlobalScopes()->first();

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        $job = new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $user,
            fromEmail: $identity->email,
            fromName: 'Sender',
            contactIds: [$contact->id],
            templateName: 'tmpl-a',
            message: $messageA,
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->first();
        $this->assertNotNull($contactMail);
        $this->assertSame($messageA->id, $contactMail->message_id);
    }

    public function test_variables_step_merges_variables_from_both_variants(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()
            ->withAbTest()
            ->for($user)
            ->create();

        $newsletter->messageA()->update([
            'subject' => 'Hello {{first_name}}',
            'html_content' => '<p>Variant A</p>',
        ]);
        $newsletter->messageB()->update([
            'subject' => 'Hi {{last_name}}',
            'html_content' => '<p>Variant B {{city}}</p>',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.variables.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('detectedVariables', fn ($vars): bool => collect($vars)->contains('first_name') &&
                collect($vars)->contains('last_name') &&
                collect($vars)->contains('city')
            )
        );
    }
}
