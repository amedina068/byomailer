<?php

namespace Tests\Feature;

use App\Jobs\SendNewsletterChunkJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Support\DetectsTemplateVariables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class TemplateVariablesTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    // --- DetectsTemplateVariables trait tests ---

    public function test_detects_simple_variables(): void
    {
        $detector = $this->makeDetector();

        $result = $detector->detectVariables('Hello {{first_name}}, welcome to {{company}}!');

        $this->assertSame(['company', 'first_name'], $result);
    }

    public function test_detects_variables_with_whitespace(): void
    {
        $detector = $this->makeDetector();

        $result = $detector->detectVariables('{{ first_name }} and {{  company  }}');

        $this->assertSame(['company', 'first_name'], $result);
    }

    public function test_deduplicates_variables(): void
    {
        $detector = $this->makeDetector();

        $result = $detector->detectVariables('{{name}} and {{name}} and {{ name }}');

        $this->assertSame(['name'], $result);
    }

    public function test_does_not_detect_the_postal_address_of_the_account(): void
    {
        $detector = $this->makeDetector();

        $this->assertSame(['name'], $detector->detectVariables('Hi {{ name }}. {{ postal_address }}'));
    }

    public function test_returns_empty_array_for_empty_content(): void
    {
        $detector = $this->makeDetector();

        $this->assertSame([], $detector->detectVariables(''));
    }

    public function test_returns_empty_array_for_no_variables(): void
    {
        $detector = $this->makeDetector();

        $this->assertSame([], $detector->detectVariables('Hello world'));
    }

    public function test_ignores_malformed_handlebars(): void
    {
        $detector = $this->makeDetector();

        $result = $detector->detectVariables('{name} and {{ }} and {{}} and {{ name}}');

        $this->assertSame(['name'], $result);
    }

    public function test_ignores_unicode_variable_names(): void
    {
        $detector = $this->makeDetector();

        $result = $detector->detectVariables('{{name}} and {{café}} and {{valid_var}}');

        $this->assertSame(['name', 'valid_var'], $result);
    }

    public function test_preserves_case_of_variables(): void
    {
        $detector = $this->makeDetector();

        $result = $detector->detectVariables('{{FirstName}} and {{lastName}}');

        $this->assertSame(['FirstName', 'lastName'], $result);
    }

    public function test_sorts_variables_alphabetically(): void
    {
        $detector = $this->makeDetector();

        $result = $detector->detectVariables('{{zebra}} {{apple}} {{mango}}');

        $this->assertSame(['apple', 'mango', 'zebra'], $result);
    }

    // --- Newsletter variables step tests ---

    public function test_newsletter_variables_step_shows_detected_variables(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message()->update([
            'subject' => 'Hello {{first_name}}',
            'html_content' => '<p>Welcome to {{company}}, {{first_name}}!</p>',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.variables.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('initialStep', 'variables')
            ->where('detectedVariables', ['company', 'first_name'])
            ->where('variableDefaults', ['company' => '', 'first_name' => ''])
        );
    }

    public function test_newsletter_variables_step_shows_empty_state_when_no_variables(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message()->update([
            'subject' => 'Hello there',
            'html_content' => '<p>No variables here.</p>',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.variables.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('newsletters/Create')
            ->where('initialStep', 'variables')
            ->where('detectedVariables', [])
            ->where('variableDefaults', [])
        );
    }

    public function test_user_can_save_newsletter_variable_defaults(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message()->update([
            'html_content' => '<p>Hello {{first_name}} from {{company}}!</p>',
        ]);

        $response = $this->actingAs($user)->patch(route('newsletters.variables.update', $newsletter), [
            'content_variables' => [
                'first_name' => 'Friend',
                'company' => 'Acme',
            ],
        ]);

        $response->assertRedirect(route('newsletters.filters.show', $newsletter));

        $newsletter->refresh();
        $this->assertSame([
            'company' => 'Acme',
            'first_name' => 'Friend',
        ], $newsletter->content_variables);
    }

    public function test_saving_variables_fills_missing_entries_with_empty_string(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message()->update([
            'html_content' => '<p>{{first_name}} at {{company}}</p>',
        ]);

        $response = $this->actingAs($user)->patch(route('newsletters.variables.update', $newsletter), [
            'content_variables' => [
                'first_name' => 'Tester',
            ],
        ]);

        $response->assertRedirect(route('newsletters.filters.show', $newsletter));

        $newsletter->refresh();
        $this->assertSame('', $newsletter->content_variables['company']);
        $this->assertSame('Tester', $newsletter->content_variables['first_name']);
    }

    public function test_saving_variables_allows_empty_defaults(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);
        $newsletter->message()->update([
            'html_content' => '<p>{{first_name}}</p>',
        ]);

        $response = $this->actingAs($user)->patch(route('newsletters.variables.update', $newsletter), [
            'content_variables' => [
                'first_name' => '',
            ],
        ]);

        $response->assertRedirect(route('newsletters.filters.show', $newsletter));

        $newsletter->refresh();
        $this->assertSame('', $newsletter->content_variables['first_name']);
    }

    public function test_variables_step_preserves_existing_defaults(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'draft',
            'content_variables' => ['first_name' => 'Buddy'],
        ]);
        $newsletter->message()->update([
            'html_content' => '<p>{{first_name}} at {{company}}</p>',
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.variables.show', $newsletter));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('variableDefaults', ['company' => '', 'first_name' => 'Buddy'])
        );
    }

    public function test_user_cannot_access_variables_step_for_sent_newsletter(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('newsletters.variables.show', $newsletter));

        $response->assertNotFound();
    }

    public function test_user_cannot_access_variables_step_of_another_users_newsletter(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $newsletter = Newsletter::factory()->for($other)->create(['status' => 'draft']);

        $response = $this->actingAs($user)->get(route('newsletters.variables.show', $newsletter));

        $response->assertNotFound();
    }

    // --- SendNewsletterChunkJob safe variable merging tests ---

    public function test_chunk_job_merges_content_variable_defaults_for_missing_contact_fields(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => [],
        ]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->withContentVariables([
                'first_name' => 'Friend',
                'company' => 'Our Team',
            ])
            ->create(['status' => 'queued', 'scheduled_at' => now(), 'template_name' => 'tmpl-test-defaults']);

        $newsletter->message()->withoutGlobalScopes()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Hello {{first_name}} from {{company}} <a href="{{unsubscribe_url}}">Unsub</a></p>',
            'text_content' => 'Hello {{first_name}} from {{company}}',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        $job = new SendNewsletterChunkJob(
            $newsletter,
            $user,
            $identity->email,
            $identity->name,
            [$contact->id],
            $newsletter->template_name,
            $newsletter->message()->withoutGlobalScopes()->first(),
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)
            ->where('mailable_id', $newsletter->id)
            ->first();

        $this->assertNotNull($contactMail);
        $this->assertSame('Friend', $contactMail->template_data['first_name']);
        $this->assertSame('Our Team', $contactMail->template_data['company']);
    }

    public function test_chunk_job_prefers_contact_attribute_over_default(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => ['first_name' => 'Casey'],
        ]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->withContentVariables(['first_name' => 'Friend'])
            ->create(['status' => 'queued', 'scheduled_at' => now(), 'template_name' => 'tmpl-test-prefer']);

        $newsletter->message()->withoutGlobalScopes()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>Hello {{first_name}}</p>',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        $job = new SendNewsletterChunkJob(
            $newsletter,
            $user,
            $identity->email,
            $identity->name,
            [$contact->id],
            $newsletter->template_name,
            $newsletter->message()->withoutGlobalScopes()->first(),
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->first();
        $this->assertSame('Casey', $contactMail->template_data['first_name']);
    }

    public function test_ses_payload_always_contains_all_declared_variables(): void
    {
        $user = User::factory()->create();
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create([
            'extra_attributes' => [],
        ]);
        $contact->lists()->attach($list);

        $newsletter = Newsletter::factory()
            ->for($user)
            ->withListFilters([$list->id])
            ->withContentVariables([
                'first_name' => '',
                'company' => '',
                'favorite_team' => '',
            ])
            ->create(['status' => 'queued', 'scheduled_at' => now(), 'template_name' => 'tmpl-test-all-vars']);

        $newsletter->message()->withoutGlobalScopes()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => $identity->name,
            'html_content' => '<p>{{first_name}} from {{company}} likes {{favorite_team}}</p>',
        ]);

        $transport = Mockery::mock(NewsletterTransport::class);
        $transport->shouldReceive('sendBatch')->once();

        $job = new SendNewsletterChunkJob(
            $newsletter,
            $user,
            $identity->email,
            $identity->name,
            [$contact->id],
            $newsletter->template_name,
            $newsletter->message()->withoutGlobalScopes()->first(),
            tenantName: 'byomailer',
        );

        $job->handle($transport);

        $contactMail = ContactMail::withoutGlobalScopes()->where('contact_id', $contact->id)->first();
        $this->assertArrayHasKey('first_name', $contactMail->template_data);
        $this->assertArrayHasKey('company', $contactMail->template_data);
        $this->assertArrayHasKey('favorite_team', $contactMail->template_data);
        $this->assertArrayHasKey('unsubscribe_url', $contactMail->template_data);
    }

    // --- Contact::getTemplateAttribute tests ---

    public function test_get_template_attribute_returns_column_value(): void
    {
        $contact = Contact::factory()->create(['name' => 'Alice']);

        $this->assertSame('Alice', $contact->getTemplateAttribute('name'));
    }

    public function test_get_template_attribute_returns_extra_attribute(): void
    {
        $contact = Contact::factory()->create([
            'extra_attributes' => ['company' => 'Acme'],
        ]);

        $this->assertSame('Acme', $contact->getTemplateAttribute('company'));
    }

    public function test_get_template_attribute_returns_json_for_an_array_value(): void
    {
        $contact = Contact::factory()->create([
            'extra_attributes' => ['interests' => ['design', 'email'], 'address' => ['city' => 'Monterrey']],
        ]);

        $this->assertSame('["design","email"]', $contact->getTemplateAttribute('interests'));
        $this->assertSame('{"city":"Monterrey"}', $contact->getTemplateAttribute('address'));
    }

    public function test_get_template_attribute_returns_default_when_missing(): void
    {
        $contact = Contact::factory()->create([
            'extra_attributes' => [],
        ]);

        $this->assertSame('Friend', $contact->getTemplateAttribute('first_name', 'Friend'));
    }

    public function test_get_template_attribute_returns_empty_string_when_no_default(): void
    {
        $contact = Contact::factory()->create([
            'extra_attributes' => [],
        ]);

        $this->assertSame('', $contact->getTemplateAttribute('nonexistent'));
    }

    public function test_get_template_attribute_never_returns_null(): void
    {
        $contact = Contact::factory()->create([
            'extra_attributes' => ['status' => null],
        ]);

        $result = $contact->getTemplateAttribute('status');

        $this->assertIsString($result);
        $this->assertSame('', $result);
    }

    public function test_get_template_attribute_prefers_column_over_extra_attribute(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'ColumnName',
            'extra_attributes' => ['name' => 'ExtraName'],
        ]);

        $this->assertSame('ColumnName', $contact->getTemplateAttribute('name'));
    }

    // --- Sequence variables tests ---

    public function test_user_can_save_sequence_variable_defaults(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update([
            'html_content' => '<p>Hello {{first_name}} from {{company}}</p>',
        ]);

        $response = $this->actingAs($user)->patch(route('sequences.variables.update', $sequence), [
            'content_variables' => [
                'first_name' => 'Friend',
                'company' => '',
            ],
        ]);

        $response->assertRedirect();

        $sequence->refresh();
        $this->assertSame('Friend', $sequence->content_variables['first_name']);
        $this->assertSame('', $sequence->content_variables['company']);
    }

    public function test_sequence_variables_update_detects_from_all_steps(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create();

        $step1 = SequenceStep::factory()->for($sequence)->create();
        $step1->message()->update(['html_content' => '<p>{{first_name}}</p>']);

        $step2 = SequenceStep::factory()->for($sequence)->create();
        $step2->message()->update(['html_content' => '<p>{{company}}</p>']);

        $response = $this->actingAs($user)->patch(route('sequences.variables.update', $sequence), [
            'content_variables' => [
                'first_name' => 'Tester',
                'company' => 'TestCo',
            ],
        ]);

        $response->assertRedirect();

        $sequence->refresh();
        $this->assertArrayHasKey('first_name', $sequence->content_variables);
        $this->assertArrayHasKey('company', $sequence->content_variables);
    }

    public function test_user_cannot_update_variables_for_another_users_sequence(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $sequence = Sequence::factory()->for($other)->create();

        $response = $this->actingAs($user)->patch(route('sequences.variables.update', $sequence), [
            'content_variables' => ['name' => 'test'],
        ]);

        $response->assertNotFound();
    }

    // --- Helpers ---

    private function makeDetector(): object
    {
        return new class
        {
            use DetectsTemplateVariables;
        };
    }
}
