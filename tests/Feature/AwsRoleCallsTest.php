<?php

namespace Tests\Feature;

use App\Enums\IdentityStatus;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use App\Services\SesDomainIdentityService;
use Aws\Command;
use Aws\Exception\AwsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\FakesAwsRole;
use Tests\TestCase;

/**
 * The AWS calls of an account with an IAM role sign with the temporary
 * credentials of the role and its session token.
 */
class AwsRoleCallsTest extends TestCase
{
    use FakesAwsRole, RefreshDatabase;

    public function test_an_identity_refresh_signs_with_the_temporary_credentials_of_the_role(): void
    {
        $this->fakeAwsRole();
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->role()->create(['region' => 'eu-west-1']);
        $identity = EmailIdentity::factory()->for($user)->pending()->create(['domain' => 'example.com']);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->once()
            ->with('example.com', self::ROLE_ACCESS_KEY, self::ROLE_SECRET_KEY, 'eu-west-1', self::ROLE_SESSION_TOKEN)
            ->andReturn(['success' => true, 'status' => 'verified', 'dkim_status' => 'SUCCESS', 'mail_from_status' => 'SUCCESS']);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->actingAs($user)
            ->patchJson(route('identities.refresh', $identity))
            ->assertOk()
            ->assertJson(['identity_status' => IdentityStatus::Verified->value]);
    }

    public function test_a_role_that_aws_refuses_gives_a_clear_error_on_the_aws_identity_list(): void
    {
        $this->fakeAwsRole()->shouldReceive('assumeRole')->andThrow(new AwsException(
            'Not authorized',
            new Command('AssumeRole'),
            ['code' => 'AccessDenied'],
        ));
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->role()->create();

        $this->actingAs($user)
            ->getJson(route('identities.aws-list'))
            ->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'AWS refused to let BYOMailer use the IAM role'));
    }

    public function test_the_dns_refresh_command_reports_a_refused_role_and_checks_the_next_identity(): void
    {
        $this->fakeAwsRole()->shouldReceive('assumeRole')->andThrow(new AwsException(
            'Not authorized',
            new Command('AssumeRole'),
            ['code' => 'AccessDenied'],
        ));

        $roleUser = User::factory()->create();
        EmailCredential::factory()->for($roleUser)->role()->create();
        EmailIdentity::factory()->for($roleUser)->pending()->create(['email' => 'news@role.example', 'domain' => 'role.example']);

        $keyUser = User::factory()->create();
        EmailCredential::factory()->for($keyUser)->create(['access_key' => 'key', 'secret_key' => 'secret', 'region' => 'us-east-1']);
        $keyIdentity = EmailIdentity::factory()->for($keyUser)->pending()->create(['email' => 'news@keys.example', 'domain' => 'keys.example']);

        $service = Mockery::mock(SesDomainIdentityService::class);
        $service->shouldReceive('getIdentityStatus')
            ->once()
            ->with('keys.example', 'key', 'secret', 'us-east-1', null)
            ->andReturn(['success' => true, 'status' => 'verified', 'dkim_status' => 'SUCCESS', 'mail_from_status' => 'SUCCESS']);
        $this->app->instance(SesDomainIdentityService::class, $service);

        $this->artisan('email-identities:refresh-dns-status')->assertSuccessful();

        $this->assertSame(IdentityStatus::Verified, $keyIdentity->fresh()->identity_status);
    }
}
