<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EmailCredential;
use App\Models\User;
use App\Services\AwsCredentialValidator;
use App\Services\SesTenantService;
use App\Services\SnsTopicService;
use Aws\Command;
use Aws\Exception\AwsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\Concerns\FakesAwsRole;
use Tests\TestCase;

class AwsRoleConnectTest extends TestCase
{
    use FakesAwsRole, RefreshDatabase;

    private const ACCOUNT_ID = '123456789012';

    private const ROLE_ARN = 'arn:aws:iam::123456789012:role/byomailer-ses';

    private const TOPIC_ARN = 'arn:aws:sns:eu-west-1:123456789012:mailautomation-1-events';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.sqs_ses_events.queue_arn' => 'arn:aws:sqs:us-east-1:111122223333:ses-events']);
    }

    public function test_the_onboarding_page_gives_the_values_for_the_stack(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('onboarding.aws'))->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/AwsCredentials')
            ->where('roleConnection.externalId', $user->client->fresh()->awsExternalId())
            ->where('roleConnection.principalArn', self::ROLE_PRINCIPAL_ARN)
            ->where('roleConnection.roleName', 'byomailer-ses')
            ->where('roleConnection.templateUrl', url('aws/byomailer-ses-role.json'))
            ->where('roleConnection.quickCreateUrl', null)
        );
    }

    public function test_the_onboarding_page_gives_a_quick_create_link_when_the_template_is_in_s3(): void
    {
        $this->fakeAwsRole();
        config(['services.ses_role.template_url' => 'https://example-bucket.s3.us-east-1.amazonaws.com/byomailer-ses-role.json']);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('onboarding.aws'));

        $link = $response->viewData('page')['props']['roleConnection']['quickCreateUrl'];
        $this->assertStringStartsWith('https://us-east-1.console.aws.amazon.com/cloudformation/home?region=us-east-1#/stacks/create/review?', $link);
        parse_str((string) parse_url(str_replace('#/stacks/create/review?', '&', $link), PHP_URL_QUERY), $query);
        $this->assertSame('https://example-bucket.s3.us-east-1.amazonaws.com/byomailer-ses-role.json', $query['templateURL']);
        $this->assertSame('byomailer-ses', $query['stackName']);
        $this->assertSame($user->client->fresh()->awsExternalId(), $query['param_ExternalId']);
        $this->assertSame(self::ROLE_PRINCIPAL_ARN, $query['param_TrustedPrincipalArn']);
        $this->assertSame('byomailer-ses', $query['param_RoleName']);
    }

    public function test_the_onboarding_page_has_no_role_connection_when_the_server_has_no_principal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('onboarding.aws'))->assertInertia(fn (Assert $page) => $page
            ->where('roleConnection', null)
        );
        $this->assertNull($user->client->fresh()->aws_external_id);
    }

    public function test_an_account_connects_through_the_role(): void
    {
        $sts = $this->fakeAwsRole();
        $user = User::factory()->create();
        $this->fakeAccountChecks();
        $this->fakeAwsSetup();

        $this->actingAs($user)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'eu-west-1'])
            ->assertRedirect(route('email-credentials.index'))
            ->assertSessionHas('success');

        $credential = EmailCredential::query()->withoutGlobalScopes()->where('client_id', $user->client_id)->sole();
        $this->assertSame(self::ROLE_ARN, $credential->role_arn);
        $this->assertNull($credential->access_key);
        $this->assertNull($credential->secret_key);
        $this->assertSame(self::ACCOUNT_ID, $credential->aws_account_id);
        $this->assertSame('eu-west-1', $credential->region);
        $this->assertSame(self::TOPIC_ARN, $credential->sns_topic_arn);
        $this->assertSame('byomailer', $credential->tenant_name);
        $sts->shouldHaveReceived('assumeRole')->with(Mockery::on(fn (array $params): bool => $params['RoleArn'] === self::ROLE_ARN
            && $params['ExternalId'] === $user->client->fresh()->awsExternalId()
            && $params['RoleSessionName'] === 'byomailer-client-'.$user->client_id));
    }

    public function test_a_key_credential_switches_to_the_role_in_place(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        $keys = EmailCredential::factory()->for($user)->create(['aws_account_id' => self::ACCOUNT_ID]);
        $teammate = User::factory()->teammateOf($user)->create();
        $this->fakeAccountChecks();
        $this->fakeAwsSetup();

        $this->actingAs($teammate)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'eu-west-1'])
            ->assertRedirect(route('email-credentials.index'));

        $credential = EmailCredential::query()->withoutGlobalScopes()->where('client_id', $user->client_id)->sole();
        $this->assertSame($keys->id, $credential->id);
        $this->assertSame(self::ROLE_ARN, $credential->role_arn);
        $this->assertNull($credential->access_key);
    }

    public function test_an_account_id_must_have_12_digits(): void
    {
        $sts = $this->fakeAwsRole();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => '12345', 'region' => 'eu-west-1'])
            ->assertSessionHasErrors(['aws_account_id' => 'Enter the 12-digit ID of your AWS account.']);

        $sts->shouldNotHaveReceived('assumeRole');
    }

    public function test_the_region_must_be_an_aws_region(): void
    {
        $sts = $this->fakeAwsRole();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'nowhere'])
            ->assertSessionHasErrors(['region' => 'The region must be a valid AWS region (e.g., us-east-1).']);

        $sts->shouldNotHaveReceived('assumeRole');
    }

    public function test_a_role_that_aws_refuses_saves_nothing(): void
    {
        $this->fakeAwsRole()->shouldReceive('assumeRole')->andThrow(new AwsException(
            'User: '.self::ROLE_PRINCIPAL_ARN.' is not authorized to perform: sts:AssumeRole',
            new Command('AssumeRole'),
            ['code' => 'AccessDenied'],
        ));
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'eu-west-1']);

        $response->assertSessionHasErrors('aws_account_id');
        $this->assertStringContainsString('AWS refused to let BYOMailer use the IAM role', session('errors')->first('aws_account_id'));
        $this->assertSame(0, EmailCredential::query()->withoutGlobalScopes()->count());
    }

    public function test_a_role_without_the_ses_permissions_saves_nothing(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        $validator = Mockery::mock(AwsCredentialValidator::class);
        $validator->shouldReceive('validate')->andReturn(['valid' => false, 'error' => 'SES access denied.']);
        $this->app->instance(AwsCredentialValidator::class, $validator);

        $this->actingAs($user)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'eu-west-1'])
            ->assertSessionHasErrors(['aws_account_id' => 'SES access denied.']);

        $this->assertSame(0, EmailCredential::query()->withoutGlobalScopes()->count());
    }

    public function test_a_failed_sns_setup_removes_the_topic_and_saves_nothing(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        $this->fakeAccountChecks();
        $sns = Mockery::mock(SnsTopicService::class);
        $sns->shouldReceive('createTopic')->andReturn(['success' => true, 'topic_arn' => self::TOPIC_ARN]);
        $sns->shouldReceive('subscribeSqsEndpoint')->andReturn(['success' => false, 'error' => 'Endpoint disabled']);
        $sns->shouldReceive('deleteTopic')->once()
            ->with(self::TOPIC_ARN, self::ROLE_ACCESS_KEY, self::ROLE_SECRET_KEY, 'eu-west-1', self::ROLE_SESSION_TOKEN)
            ->andReturn(['success' => true]);
        $this->app->instance(SnsTopicService::class, $sns);

        $this->actingAs($user)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'eu-west-1'])
            ->assertSessionHasErrors(['aws_account_id' => 'Failed to subscribe SNS topic: Endpoint disabled']);

        $this->assertSame(0, EmailCredential::query()->withoutGlobalScopes()->count());
    }

    public function test_the_route_is_not_found_when_the_server_has_no_principal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'eu-west-1'])
            ->assertNotFound();
    }

    public function test_a_viewer_cannot_connect_a_role(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        $viewer = User::factory()->teammateOf($user)->create(['role' => UserRole::Viewer]);

        $this->actingAs($viewer)
            ->post(route('onboarding.aws.role'), ['aws_account_id' => self::ACCOUNT_ID, 'region' => 'eu-west-1'])
            ->assertForbidden();
    }

    public function test_new_access_keys_in_settings_switch_a_role_back_to_keys(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->role()->create(['aws_account_id' => self::ACCOUNT_ID]);
        $validator = Mockery::mock(AwsCredentialValidator::class);
        $validator->shouldReceive('fetchAccountId')->andReturn(self::ACCOUNT_ID);
        $this->app->instance(AwsCredentialValidator::class, $validator);

        $this->actingAs($user)->post(route('email-credentials.store'), [
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'region' => 'us-east-1',
        ])->assertSessionHasNoErrors();

        $credential = EmailCredential::query()->withoutGlobalScopes()->where('client_id', $user->client_id)->sole();
        $this->assertNull($credential->role_arn);
        $this->assertSame('AKIAIOSFODNN7EXAMPLE', $credential->access_key);
    }

    public function test_the_settings_page_shows_the_role_and_no_key_hint(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->role()->create(['aws_account_id' => self::ACCOUNT_ID]);

        $this->actingAs($user)->get(route('email-credentials.index'))->assertInertia(fn (Assert $page) => $page
            ->where('credential.uses_role', true)
            ->where('credential.role_arn', self::ROLE_ARN)
            ->where('credential.access_key_hint', null)
            ->where('roleConnection.roleName', 'byomailer-ses')
        );
    }

    private function fakeAccountChecks(): void
    {
        $validator = Mockery::mock(AwsCredentialValidator::class);
        $validator->shouldReceive('validate')
            ->once()
            ->with(self::ROLE_ACCESS_KEY, self::ROLE_SECRET_KEY, 'eu-west-1', self::ROLE_SESSION_TOKEN)
            ->andReturn(['valid' => true, 'account_id' => self::ACCOUNT_ID]);
        $this->app->instance(AwsCredentialValidator::class, $validator);
    }

    private function fakeAwsSetup(): void
    {
        $sns = Mockery::mock(SnsTopicService::class);
        $sns->shouldReceive('createTopic')->once()
            ->with(Mockery::type('string'), self::ROLE_ACCESS_KEY, self::ROLE_SECRET_KEY, 'eu-west-1', self::ROLE_SESSION_TOKEN)
            ->andReturn(['success' => true, 'topic_arn' => self::TOPIC_ARN]);
        $sns->shouldReceive('subscribeSqsEndpoint')->once()
            ->with(self::TOPIC_ARN, 'arn:aws:sqs:us-east-1:111122223333:ses-events', self::ROLE_ACCESS_KEY, self::ROLE_SECRET_KEY, 'eu-west-1', self::ROLE_SESSION_TOKEN)
            ->andReturn(['success' => true, 'subscription_arn' => self::TOPIC_ARN.':subscription']);
        $this->app->instance(SnsTopicService::class, $sns);

        $tenant = Mockery::mock(SesTenantService::class);
        $tenant->shouldReceive('provisionTenant')->once()
            ->with('byomailer', self::ROLE_ACCESS_KEY, self::ROLE_SECRET_KEY, 'eu-west-1', self::ROLE_SESSION_TOKEN)
            ->andReturn(['success' => true]);
        $this->app->instance(SesTenantService::class, $tenant);
    }
}
