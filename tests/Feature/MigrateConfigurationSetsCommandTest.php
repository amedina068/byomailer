<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateConfigurationSetsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_copies_configuration_set_data_to_identities(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->withConfigurationSet()->create([
            'open_tracking_enabled' => true,
            'click_tracking_enabled' => false,
            'custom_tracking_domain' => 'track.example.com',
            'custom_tracking_domain_verified_at' => now()->subDay(),
        ]);
        $identity = EmailIdentity::factory()->for($user)->create(['domain' => 'example.com']);

        $this->artisan('ses:migrate-configuration-sets')
            ->assertSuccessful();

        $identity->refresh();
        $this->assertSame($credential->configuration_set_name, $identity->configuration_set_name);
        $this->assertNotNull($identity->configuration_set_created_at);
        $this->assertTrue($identity->open_tracking_enabled);
        $this->assertFalse($identity->click_tracking_enabled);
        $this->assertSame('track.example.com', $identity->custom_tracking_domain);
        $this->assertNotNull($identity->custom_tracking_domain_verified_at);
    }

    public function test_it_copies_to_multiple_identities(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->withConfigurationSet()->create();
        $identity1 = EmailIdentity::factory()->for($user)->create(['domain' => 'one.com']);
        $identity2 = EmailIdentity::factory()->for($user)->create(['domain' => 'two.com']);

        $this->artisan('ses:migrate-configuration-sets')
            ->assertSuccessful();

        $identity1->refresh();
        $identity2->refresh();
        $this->assertSame($credential->configuration_set_name, $identity1->configuration_set_name);
        $this->assertSame($credential->configuration_set_name, $identity2->configuration_set_name);
    }

    public function test_dry_run_does_not_modify_data(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->withConfigurationSet()->create();
        $identity = EmailIdentity::factory()->for($user)->create(['domain' => 'example.com']);

        $this->artisan('ses:migrate-configuration-sets', ['--dry-run' => true])
            ->assertSuccessful();

        $identity->refresh();
        $this->assertNull($identity->configuration_set_name);
    }

    public function test_it_skips_already_migrated_identities(): void
    {
        $user = User::factory()->create();
        $credential = EmailCredential::factory()->for($user)->withConfigurationSet()->create();
        $existingConfigSet = 'existing-config-set';
        EmailIdentity::factory()->for($user)->withConfigurationSet($existingConfigSet)->create([
            'domain' => 'example.com',
        ]);

        $this->artisan('ses:migrate-configuration-sets')
            ->expectsOutputToContain('No identities to migrate')
            ->assertSuccessful();

        $identity = $user->emailIdentities()->withoutGlobalScopes()->first();
        $this->assertSame($existingConfigSet, $identity->configuration_set_name);
    }

    public function test_it_can_filter_by_user_id(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $credential1 = EmailCredential::factory()->for($user1)->withConfigurationSet()->create();
        EmailCredential::factory()->for($user2)->withConfigurationSet()->create();
        $identity1 = EmailIdentity::factory()->for($user1)->create(['domain' => 'one.com']);
        $identity2 = EmailIdentity::factory()->for($user2)->create(['domain' => 'two.com']);

        $this->artisan('ses:migrate-configuration-sets', ['--user' => $user1->id])
            ->assertSuccessful();

        $identity1->refresh();
        $identity2->refresh();
        $this->assertSame($credential1->configuration_set_name, $identity1->configuration_set_name);
        $this->assertNull($identity2->configuration_set_name);
    }

    public function test_the_user_option_migrates_the_identities_of_the_client_of_the_user(): void
    {
        $owner = User::factory()->create();
        $teammate = User::factory()->for($owner->client)->create();
        $credential = EmailCredential::factory()->for($owner)->withConfigurationSet()->create();
        $identity = EmailIdentity::factory()->for($teammate)->create(['domain' => 'team.com']);
        $foreign = EmailIdentity::factory()->create(['domain' => 'other.com']);

        $this->artisan('ses:migrate-configuration-sets', ['--user' => $teammate->id])
            ->assertSuccessful();

        $this->assertSame($credential->configuration_set_name, $identity->refresh()->configuration_set_name);
        $this->assertNull($foreign->refresh()->configuration_set_name);
    }

    public function test_it_reports_no_credentials_found(): void
    {
        $this->artisan('ses:migrate-configuration-sets')
            ->expectsOutputToContain('No SES credentials with configuration sets found.')
            ->assertSuccessful();
    }

    public function test_it_skips_credentials_without_configuration_set(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create([
            'configuration_set_name' => null,
        ]);
        EmailIdentity::factory()->for($user)->create(['domain' => 'example.com']);

        $this->artisan('ses:migrate-configuration-sets')
            ->expectsOutputToContain('No SES credentials with configuration sets found.')
            ->assertSuccessful();
    }
}
