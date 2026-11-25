<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_active_templates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailTemplate::factory()->for($user->client)->count(2)->create();
        EmailTemplate::factory()->for($user->client)->create(['archived_at' => now()]);

        $response = $this->getJson('/api/v1/email-templates');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_can_request_archived_templates(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailTemplate::factory()->for($user->client)->create();
        EmailTemplate::factory()->for($user->client)->create(['archived_at' => now()]);

        $response = $this->getJson('/api/v1/email-templates?archived=true');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_default_endpoint_returns_default_template(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailTemplate::factory()->for($user->client)->create();
        $default = EmailTemplate::factory()->for($user->client)->create(['is_default' => true]);

        $response = $this->getJson('/api/v1/email-templates/default');

        $response->assertOk()
            ->assertJsonPath('data.id', $default->id)
            ->assertJsonPath('data.is_default', true);
    }

    public function test_default_endpoint_returns_404_when_no_default(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        EmailTemplate::factory()->for($user->client)->create(['is_default' => false]);

        $response = $this->getJson('/api/v1/email-templates/default');

        $response->assertNotFound();
    }

    public function test_show_endpoint_returns_template(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $template = EmailTemplate::factory()->for($user->client)->create();

        $response = $this->getJson("/api/v1/email-templates/{$template->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $template->id);
    }

    public function test_templates_do_not_leak_across_clients(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $other = Client::factory()->create();
        $template = EmailTemplate::factory()->for($other)->create();

        $response = $this->getJson("/api/v1/email-templates/{$template->id}");

        $response->assertNotFound();
    }
}
