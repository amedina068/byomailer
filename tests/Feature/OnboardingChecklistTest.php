<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use App\Models\UserStorageSetting;
use App\Support\OnboardingChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingChecklistTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_client_has_no_completed_steps(): void
    {
        $user = User::factory()->create();

        $result = OnboardingChecklist::forClient($user->client);

        $this->assertSame(0, $result['completed']);
        $this->assertSame(6, $result['total']);
        $this->assertCount(6, $result['steps']);

        foreach ($result['steps'] as $step) {
            $this->assertFalse($step['completed']);
        }
    }

    public function test_aws_credentials_step_is_completed_when_ses_credential_exists(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create(['type' => 'ses']);

        $result = OnboardingChecklist::forClient($user->client);

        $this->assertTrue($result['steps'][0]['completed']);
        $this->assertSame('aws_credentials', $result['steps'][0]['key']);
        $this->assertSame(1, $result['completed']);
    }

    public function test_email_identity_step_is_completed_when_identity_exists(): void
    {
        $user = User::factory()->create();
        EmailIdentity::factory()->for($user)->create();

        $result = OnboardingChecklist::forClient($user->client);

        $this->assertTrue($result['steps'][1]['completed']);
        $this->assertSame('email_identity', $result['steps'][1]['key']);
    }

    public function test_storage_step_is_completed_when_storage_setting_exists(): void
    {
        $user = User::factory()->create();
        UserStorageSetting::factory()->for($user)->create();

        $result = OnboardingChecklist::forClient($user->client);

        $this->assertTrue($result['steps'][3]['completed']);
        $this->assertSame('storage', $result['steps'][3]['key']);
    }

    public function test_import_contacts_step_is_completed_when_contacts_exist(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create();

        $result = OnboardingChecklist::forClient($user->client);

        $this->assertTrue($result['steps'][4]['completed']);
        $this->assertSame('import_contacts', $result['steps'][4]['key']);
    }

    public function test_send_newsletter_step_is_completed_when_newsletter_is_sent(): void
    {
        $user = User::factory()->create();
        Newsletter::factory()->for($user)->create([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $result = OnboardingChecklist::forClient($user->client);

        $this->assertTrue($result['steps'][5]['completed']);
        $this->assertSame('send_newsletter', $result['steps'][5]['key']);
    }

    public function test_send_newsletter_step_is_not_completed_for_draft_newsletters(): void
    {
        $user = User::factory()->create();
        Newsletter::factory()->for($user)->create([
            'status' => 'draft',
        ]);

        $result = OnboardingChecklist::forClient($user->client);

        $this->assertFalse($result['steps'][5]['completed']);
    }

    public function test_all_steps_completed_counts_correctly(): void
    {
        $user = User::factory()->create();

        EmailCredential::factory()->for($user)->create(['type' => 'ses']);
        EmailIdentity::factory()->for($user)->create();
        UserStorageSetting::factory()->for($user)->create();
        Contact::factory()->for($user)->create();
        Newsletter::factory()->for($user)->create([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $result = OnboardingChecklist::forClient($user->client->fresh());

        $this->assertSame(5, $result['completed']);
        $this->assertSame(6, $result['total']);
    }

    public function test_checklist_is_shared_via_inertia(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();

        $props = $response->original->getData()['page']['props'];

        $this->assertArrayHasKey('onboardingChecklist', $props);
    }

    public function test_each_step_has_required_keys(): void
    {
        $user = User::factory()->create();

        $result = OnboardingChecklist::forClient($user->client);

        foreach ($result['steps'] as $step) {
            $this->assertArrayHasKey('key', $step);
            $this->assertArrayHasKey('label', $step);
            $this->assertArrayHasKey('completed', $step);
            $this->assertArrayHasKey('href', $step);
        }
    }

    public function test_steps_are_in_correct_order(): void
    {
        $user = User::factory()->create();

        $result = OnboardingChecklist::forClient($user->client);

        $expectedOrder = [
            'aws_credentials',
            'email_identity',
            'domain_tracking',
            'storage',
            'import_contacts',
            'send_newsletter',
        ];

        $actualOrder = array_column($result['steps'], 'key');

        $this->assertSame($expectedOrder, $actualOrder);
    }
}
