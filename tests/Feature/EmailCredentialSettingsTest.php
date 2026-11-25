<?php

namespace Tests\Feature;

use App\Jobs\ProcessSesEventJob;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\AwsCredentialValidator;
use App\Services\SesClientManager;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class EmailCredentialSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_email_credentials_settings_page(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLEUSER',
            'secret_key' => 'example-secret',
            'region' => 'us-east-1',
        ]);

        $response = $this->actingAs($user)->get(route('email-credentials.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/EmailCredentials')
            ->has('credential')
        );
    }

    public function test_user_can_store_ses_credentials(): void
    {
        $user = User::factory()->create();
        $this->mockAccountId('123456789012');

        $payload = [
            'access_key' => 'AKIA123456789EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-west-2',
        ];

        $response = $this->actingAs($user)->post(route('email-credentials.store'), $payload);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $credential = EmailCredential::query()->where('user_id', $user->id)->first();

        $this->assertNotNull($credential);
        $this->assertSame('ses', $credential->type);
        $this->assertSame($payload['access_key'], $credential->access_key);
        $this->assertSame($payload['secret_key'], $credential->secret_key);
        $this->assertSame($payload['region'], $credential->region);
    }

    public function test_storing_credentials_updates_existing_record(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'access_key' => 'OLDKEY',
            'secret_key' => 'OLDSECRETKEYVALUE1234567890OLDSECRETKEY',
            'region' => 'us-east-1',
        ]);
        $this->mockAccountId($credential->aws_account_id);

        $payload = [
            'access_key' => 'AKIANEWKEYVALUE1234',
            'secret_key' => 'NEWSECRETKEYVALUE1234567890SECRETKEYVALUE1234',
            'region' => 'eu-central-1',
        ];

        $response = $this->actingAs($user)->post(route('email-credentials.store'), $payload);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $credential = EmailCredential::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame($payload['access_key'], $credential->access_key);
        $this->assertSame($payload['secret_key'], $credential->secret_key);
        $this->assertSame($payload['region'], $credential->region);
        $this->assertSame(1, EmailCredential::query()->where('user_id', $user->id)->count());
    }

    public function test_storing_new_keys_keeps_the_aws_setup_of_the_credential(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:byomailer-events',
            'tenant_name' => 'byomailer-tenant',
            'aws_account_id' => '123456789012',
        ]);
        $this->mockAccountId('123456789012');

        $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIANEWKEYVALUE1234',
            'secret_key' => 'NEWSECRETKEYVALUE1234567890SECRETKEYVALUE1234',
            'region' => 'us-east-1',
        ])->assertSessionHasNoErrors();

        $stored = EmailCredential::query()->where('user_id', $user->id)->sole();

        $this->assertTrue($stored->is($credential));
        $this->assertSame('AKIANEWKEYVALUE1234', $stored->access_key);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:byomailer-events', $stored->sns_topic_arn);
        $this->assertSame('byomailer-tenant', $stored->tenant_name);
        $this->assertSame('123456789012', $stored->aws_account_id);
    }

    public function test_validation_errors_are_returned_for_invalid_payload(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->from(route('email-credentials.index'))
            ->post(route('email-credentials.store'), [
                'access_key' => 'short',
                'secret_key' => 'tiny',
                'region' => 'invalid-region',
            ]);

        $response->assertRedirect(route('email-credentials.index'));
        $response->assertSessionHasErrors(['access_key', 'secret_key', 'region']);
    }

    public function test_guest_cannot_access_email_credentials_routes(): void
    {
        $this->get(route('email-credentials.index'))->assertRedirect(route('login'));
        $this->post(route('email-credentials.store'), [])->assertRedirect(route('login'));
    }

    public function test_user_can_refresh_ses_quota(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLEUSER',
            'secret_key' => 'example-secret',
            'region' => 'us-east-1',
        ]);

        $quotaData = [
            'production_access_enabled' => true,
            'sending_enabled' => true,
            'max_24_hour_send' => 50000,
            'max_send_rate' => 14,
            'sent_last_24_hours' => 1234,
        ];

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdateQuota')
            ->once()
            ->with(Mockery::on(fn ($u) => $u->id === $user->id))
            ->andReturn($quotaData);

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $response = $this->actingAs($user)->post(route('email-credentials.refresh-quota'));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'quota' => $quotaData,
        ]);
    }

    public function test_refresh_quota_returns_error_when_no_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('email-credentials.refresh-quota'));

        $response->assertStatus(404);
        $response->assertJson(['error' => 'No SES credentials found.']);
    }

    public function test_refresh_quota_returns_error_when_fetch_fails(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLEUSER',
            'secret_key' => 'example-secret',
            'region' => 'us-east-1',
        ]);

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('fetchAndUpdateQuota')
            ->once()
            ->andThrow(new Exception('Invalid credentials'));

        $this->app->instance(SesClientManager::class, $mockClientManager);

        $response = $this->actingAs($user)->post(route('email-credentials.refresh-quota'));

        $response->assertStatus(500);
        $response->assertJson(['error' => 'Failed to fetch quota: Invalid credentials']);
    }

    public function test_credential_resource_includes_quota_fields(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAEXAMPLEUSER',
            'secret_key' => 'example-secret',
            'region' => 'us-east-1',
            'production_access_enabled' => true,
            'sending_enabled' => true,
            'max_24_hour_send' => 50000,
            'max_send_rate' => 14,
            'sent_last_24_hours' => 1234,
            'quota_fetched_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('email-credentials.index'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('settings/EmailCredentials')
            ->has('credential', fn (Assert $credential) => $credential
                ->where('production_access_enabled', true)
                ->where('sending_enabled', true)
                ->where('max_24_hour_send', 50000)
                ->where('max_send_rate', 14)
                ->where('sent_last_24_hours', 1234)
                ->has('quota_fetched_at')
                ->etc()
            )
        );
    }

    public function test_store_allows_replacing_own_mailer_at_plan_limit(): void
    {
        $user = User::factory()->create();

        $credential = EmailCredential::factory()->for($user)->create([
            'type' => 'ses',
            'access_key' => 'AKIAOLDKEY',
        ]);
        $this->mockAccountId($credential->aws_account_id);

        $response = $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIA123456789EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-west-2',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(
            'AKIA123456789EXAMPLE',
            EmailCredential::query()->where('user_id', $user->id)->value('access_key')
        );
    }

    public function test_saving_new_keys_keeps_the_sns_and_tenant_wiring(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->withTenant()->create([
            'aws_account_id' => '123456789012',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation-events',
            'sns_subscription_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation-events:sub',
            'sns_subscription_confirmed_at' => now(),
            'open_tracking_enabled' => false,
        ]);
        $this->mockAccountId('123456789012');

        $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIAROTATEDKEY000001',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYROTATEDKEY',
            'region' => 'us-east-1',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $saved = EmailCredential::query()->sole();

        $this->assertSame($credential->id, $saved->id);
        $this->assertSame('AKIAROTATEDKEY000001', $saved->access_key);
        $this->assertSame('byomailer', $saved->tenant_name);
        $this->assertSame('123456789012', $saved->aws_account_id);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation-events', $saved->sns_topic_arn);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation-events:sub', $saved->sns_subscription_arn);
        $this->assertNotNull($saved->sns_subscription_confirmed_at);
        $this->assertFalse($saved->open_tracking_enabled);
    }

    public function test_ses_event_on_the_topic_still_reaches_the_client_after_a_key_save(): void
    {
        $user = User::factory()->create();
        $topicArn = 'arn:aws:sns:us-east-1:123456789012:mailautomation-events';
        EmailCredential::factory()->for($user)->withTenant()->create([
            'aws_account_id' => '123456789012',
            'sns_topic_arn' => $topicArn,
        ]);
        $contact = Contact::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $mail = ContactMail::factory()->for($contact)->for($newsletter, 'mailable')
            ->create(['status' => 'delivered', 'template_data' => []]);
        $this->mockAccountId('123456789012');

        $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIAROTATEDKEY000001',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYROTATEDKEY',
            'region' => 'us-east-1',
        ])->assertSessionHasNoErrors();

        ProcessSesEventJob::dispatchSync([
            'Type' => 'Notification',
            'MessageId' => (string) Str::uuid(),
            'TopicArn' => $topicArn,
            'Message' => json_encode(['events' => [[
                'eventType' => 'Bounce',
                'mail' => ['tags' => ['contact_mail_uuid' => [$mail->uuid]]],
                'bounce' => [
                    'bounceType' => 'Permanent',
                    'bounceSubType' => 'NoEmail',
                    'timestamp' => now()->toISOString(),
                ],
            ]]]),
        ]);

        $this->assertSame('bounced', $mail->refresh()->status);
    }

    public function test_teammate_can_save_new_keys_for_the_client_credential(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->create(['client_id' => $owner->client_id]);
        $credential = EmailCredential::factory()->for($owner)->withTenant()->create([
            'aws_account_id' => '123456789012',
            'sns_topic_arn' => 'arn:aws:sns:us-east-1:123456789012:mailautomation-events',
        ]);
        $this->mockAccountId('123456789012');

        $this->actingAs($teammate)->post(route('email-credentials.store'), [
            'access_key' => 'AKIAROTATEDKEY000001',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYROTATEDKEY',
            'region' => 'us-east-1',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $saved = EmailCredential::query()->sole();

        $this->assertSame($credential->id, $saved->id);
        $this->assertSame($owner->id, $saved->user_id);
        $this->assertSame('AKIAROTATEDKEY000001', $saved->access_key);
        $this->assertSame('arn:aws:sns:us-east-1:123456789012:mailautomation-events', $saved->sns_topic_arn);
    }

    public function test_keys_of_another_aws_account_are_refused(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'access_key' => 'AKIAOLDKEY0000000001',
            'aws_account_id' => '111111111111',
        ]);
        $this->mockAccountId('222222222222');

        $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIAOTHERACCOUNT0001',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYOTHERACCNT',
            'region' => 'us-east-1',
        ])->assertSessionHasErrors([
            'access_key' => 'These keys belong to a different AWS account. To connect a new AWS account, run the AWS setup again.',
        ]);

        $this->assertSame('AKIAOLDKEY0000000001', EmailCredential::query()->sole()->access_key);
    }

    public function test_keys_that_aws_does_not_accept_are_refused(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['access_key' => 'AKIAOLDKEY0000000001']);
        $this->mockAccountId(null);

        $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIABADKEY0000000001',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYBADKEYVALUE',
            'region' => 'us-east-1',
        ])->assertSessionHasErrors([
            'access_key' => 'AWS did not accept these keys. Check the access key, the secret key and the region.',
        ]);

        $this->assertSame('AKIAOLDKEY0000000001', EmailCredential::query()->sole()->access_key);
    }

    public function test_saving_keys_fills_a_missing_aws_account_id(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['aws_account_id' => null]);
        $this->mockAccountId('123456789012');

        $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIAROTATEDKEY000001',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYROTATEDKEY',
            'region' => 'us-east-1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('123456789012', EmailCredential::query()->sole()->aws_account_id);
    }

    private function mockAccountId(?string $accountId): void
    {
        $validator = Mockery::mock(AwsCredentialValidator::class);
        $validator->shouldReceive('fetchAccountId')->once()->andReturn($accountId);
        $this->app->instance(AwsCredentialValidator::class, $validator);
    }
}
