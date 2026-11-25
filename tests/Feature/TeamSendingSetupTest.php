<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Contact;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use App\Services\AwsCredentialValidator;
use App\Services\ContactReferenceDataService;
use App\Services\SesClientManager;
use App\Services\SesConfigurationSetService;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * The AWS credential, the sequences and the automations belong to the client.
 * Each member of the client uses the records that another member made.
 */
class TeamSendingSetupTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private User $owner;

    private User $teammate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->teammate = User::factory()->create(['client_id' => $this->owner->client_id]);
    }

    public function test_a_teammate_reads_the_aws_credential_of_the_client(): void
    {
        $credential = EmailCredential::factory()->for($this->owner)->create();
        EmailCredential::factory()->create();
        $this->actingAsTenant($this->teammate->client_id);

        $this->assertTrue($this->teammate->sesCredential->is($credential));
        $this->assertTrue($this->teammate->emailCredentials()->sole()->is($credential));

        $this->actingAs($this->teammate)->get(route('email-credentials.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('credential.id', $credential->id));
    }

    public function test_new_keys_from_a_teammate_update_the_credential_of_the_client(): void
    {
        $credential = EmailCredential::factory()->for($this->owner)->create(['region' => 'us-east-1', 'aws_account_id' => '123456789012']);

        $validator = Mockery::mock(AwsCredentialValidator::class);
        $validator->shouldReceive('fetchAccountId')->once()->andReturn('123456789012');
        $this->app->instance(AwsCredentialValidator::class, $validator);

        $this->actingAs($this->teammate)->post(route('email-credentials.store'), [
            'access_key' => 'AKIAEXAMPLETEAMMATE1',
            'secret_key' => str_repeat('s', 40),
            'region' => 'eu-west-1',
        ])->assertRedirect();

        $this->assertSame(1, EmailCredential::query()->withoutGlobalScopes()->where('client_id', $this->owner->client_id)->count());
        $this->assertSame('eu-west-1', $credential->fresh()->region);
        $this->assertSame($this->owner->id, $credential->fresh()->user_id);
    }

    public function test_a_teammate_sees_and_opens_the_sequences_of_the_client(): void
    {
        $sequence = Sequence::factory()->for($this->owner)->create(['status' => 'draft']);
        $foreign = Sequence::factory()->create();

        $this->actingAs($this->teammate)->get(route('sequences.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('sequences', 1)
                ->where('sequences.0.id', $sequence->id));

        $this->actingAs($this->teammate)->get(route('sequences.show', $sequence))->assertOk();
        $this->actingAs($this->teammate)->get(route('sequences.show', $foreign))->assertNotFound();
    }

    public function test_a_teammate_adds_a_step_with_the_sender_identity_of_the_client(): void
    {
        $sequence = Sequence::factory()->for($this->owner)->create(['status' => 'draft']);
        $identity = EmailIdentity::factory()->for($this->owner)->create();

        $this->actingAs($this->teammate)->post(route('sequences.steps.store', $sequence), [
            'delay_days' => 1,
            'delay_type' => 'days',
            'subject' => 'Welcome',
            'html_content' => '<p>Hello</p>',
            'email_identity_id' => $identity->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($identity->id, $sequence->steps()->sole()->message->email_identity_id);
    }

    public function test_a_teammate_sees_the_sender_identities_of_the_client(): void
    {
        $identity = EmailIdentity::factory()->for($this->owner)->create();
        EmailIdentity::factory()->create();
        $sequence = Sequence::factory()->for($this->owner)->create(['status' => 'draft']);
        $list = MailList::factory()->for($this->owner)->create();

        $this->actingAs($this->teammate)->get(route('identities.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('identities', 1)
                ->where('identities.0.id', $identity->id));

        $this->actingAs($this->teammate)->get(route('sequences.show', $sequence))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('emailIdentities', 1)
                ->where('emailIdentities.0.id', $identity->id));

        $this->actingAs($this->teammate)->get(route('lists.edit', $list))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('emailIdentities', 1)
                ->where('emailIdentities.0.id', $identity->id));
    }

    public function test_a_teammate_sends_the_opt_in_of_a_list_from_the_sender_identity_of_the_client(): void
    {
        $identity = EmailIdentity::factory()->for($this->owner)->create();
        $list = MailList::factory()->for($this->owner)->create();

        $this->actingAs($this->teammate)->put(route('lists.update', $list), [
            'name' => 'Newsletter',
            'opt_in_enabled' => true,
            'opt_in_subject' => 'Confirm',
            'opt_in_html_content' => '<p>Confirm {{ opt_in_url }}</p>',
            'opt_in_email_identity_id' => $identity->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($identity->id, $list->message()->sole()->email_identity_id);
    }

    public function test_a_teammate_cannot_add_a_sender_identity_that_the_client_has(): void
    {
        EmailIdentity::factory()->for($this->owner)->create(['email' => 'hello@example.com']);

        $this->actingAs($this->teammate)
            ->postJson(route('identities.store'), ['email' => 'hello@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    }

    public function test_a_member_can_add_a_sender_identity_that_another_client_has(): void
    {
        EmailIdentity::factory()->create(['email' => 'hello@example.com']);

        $response = $this->actingAs($this->teammate)
            ->postJson(route('identities.store'), ['email' => 'hello@example.com']);

        $this->assertNotContains('The email has already been taken.', $response->json('errors.email', []));
    }

    public function test_the_enrollment_list_offers_the_live_sequences_of_the_client(): void
    {
        $sequence = Sequence::factory()->for($this->owner)->create(['status' => 'live']);
        Sequence::factory()->create(['status' => 'live']);
        $this->actingAsTenant($this->teammate->client_id);

        $live = app(ContactReferenceDataService::class)->liveSequences($this->teammate);

        $this->assertSame([$sequence->id], $live->pluck('id')->all());
    }

    public function test_a_teammate_enrolls_a_contact_in_a_sequence_of_the_client(): void
    {
        $sequence = Sequence::factory()->for($this->owner)->create(['status' => 'live']);
        $contact = Contact::factory()->for($this->teammate)->create();

        $this->actingAs($this->teammate)->post(route('contacts.sequences.enroll'), [
            'sequence_id' => $sequence->id,
            'contact_ids' => [$contact->id],
        ])->assertSessionHasNoErrors();

        $this->assertTrue($contact->sequences()->whereKey($sequence->id)->exists());
    }

    public function test_a_teammate_manages_the_automations_of_the_client(): void
    {
        $automation = Automation::factory()->for($this->owner)->create();
        Automation::factory()->create();

        $this->actingAs($this->teammate)->get(route('automations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('automations', 1)
                ->where('automations.0.id', $automation->id));

        $this->actingAs($this->teammate)->get(route('automations.edit', $automation))->assertOk();

        $this->actingAs($this->teammate)->patch(route('automations.status.update', $automation), ['is_live' => false]);
        $this->assertFalse($automation->fresh()->is_live);

        $this->actingAs($this->teammate)->delete(route('automations.destroy', $automation));
        $this->assertModelMissing($automation);
    }

    public function test_a_teammate_cannot_open_the_automation_of_another_client(): void
    {
        $foreign = Automation::factory()->create();

        $this->actingAs($this->teammate)->get(route('automations.edit', $foreign))->assertNotFound();
        $this->actingAs($this->teammate)->put(route('automations.update', $foreign), [])->assertNotFound();
    }

    public function test_an_automation_runs_for_a_contact_that_a_teammate_added(): void
    {
        $tag = Tag::factory()->for($this->owner)->create();
        $list = MailList::factory()->for($this->owner)->create();
        Automation::factory()->for($this->owner)->create([
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_list', 'value' => $list->id]],
        ]);
        $contact = Contact::factory()->for($this->teammate)->create();
        $this->actingAsTenant($this->teammate->client_id);

        $contact->syncTags([$tag->id], true);

        $this->assertTrue($contact->lists()->whereKey($list->id)->exists());
    }

    public function test_an_automation_does_not_run_for_the_contact_of_another_client(): void
    {
        $tag = Tag::factory()->for($this->owner)->create();
        $list = MailList::factory()->for($this->owner)->create();
        Automation::factory()->for($this->owner)->create([
            'condition' => ['type' => 'tag_added', 'value' => $tag->id],
            'actions' => [['type' => 'add_list', 'value' => $list->id]],
        ]);
        $stranger = Contact::factory()->create();

        Tenant::run($stranger->client_id, fn () => $stranger->syncTags([$tag->id], true));

        $this->assertFalse($stranger->lists()->withoutGlobalScopes()->whereKey($list->id)->exists());
    }

    public function test_the_configuration_set_sync_runs_one_time_for_each_credential(): void
    {
        EmailCredential::factory()->for($this->owner)->create();
        EmailIdentity::factory()->for($this->teammate)->withConfigurationSet()->create();

        $configService = Mockery::mock(SesConfigurationSetService::class);
        $configService->shouldReceive('fetchTrackingStatus')->once()->andReturn([
            'success' => true,
            'open_tracking_enabled' => false,
            'click_tracking_enabled' => false,
        ]);
        $this->app->instance(SesConfigurationSetService::class, $configService);

        $clientManager = Mockery::mock(SesClientManager::class);
        $clientManager->shouldReceive('fetchAndUpdatePermissions')->once()->andReturn(['can_send_email' => true]);
        $this->app->instance(SesClientManager::class, $clientManager);

        $this->artisan('ses:sync-configuration-sets')->assertSuccessful();
    }
}
