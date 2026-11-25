<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An API client that does not send `Accept: application/json` must get the
 * same JSON errors as a client that sends it: no redirect to the login page
 * and no HTML error page.
 */
class ApiErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_without_a_token_gets_401_json_without_the_accept_header(): void
    {
        $this->completeInstanceSetup();

        $response = $this->get('/api/v1/contacts');

        $response->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_request_with_a_wrong_token_gets_401_json_without_the_accept_header(): void
    {
        $this->completeInstanceSetup();

        $response = $this->get('/api/v1/contacts', ['Authorization' => 'Bearer not-a-token']);

        $response->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_validation_error_gets_422_json_without_the_accept_header(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->post('/api/v1/tags', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_a_missing_record_gets_404_json_that_names_the_record(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->get('/api/v1/contacts/999999');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'No contact with ID 999999 exists in this account.']);
    }

    public function test_a_missing_list_is_called_a_list_in_the_404_message(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->getJson('/api/v1/lists/999999/topics');

        $response->assertNotFound()
            ->assertExactJson(['message' => 'No list with ID 999999 exists in this account.']);
    }

    public function test_a_missing_tag_bound_in_the_route_gets_the_same_404_message(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->patchJson('/api/v1/tags/999999', ['name' => 'VIP']);

        $response->assertNotFound()
            ->assertExactJson(['message' => 'No tag with ID 999999 exists in this account.']);
    }

    public function test_an_unknown_api_path_gets_404_json_without_the_accept_header(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->get('/api/v1/not-a-resource');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_a_team_member_who_is_not_an_admin_gets_a_403_message_on_webhook_endpoints(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs(User::factory()->teammateOf($admin)->create(), ['*']);

        $response = $this->getJson('/api/v1/webhook-endpoints');

        $response->assertForbidden()
            ->assertJsonPath('message', 'Only an admin of the account can manage webhook endpoints.');
    }

    public function test_a_team_member_who_is_not_an_admin_gets_the_same_403_message_on_create(): void
    {
        $admin = User::factory()->create();
        Sanctum::actingAs(User::factory()->teammateOf($admin)->create(), ['*']);

        $response = $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://example.com/hook',
            'events' => ['contact.subscribed'],
        ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'Only an admin of the account can manage webhook endpoints.');
    }

    public function test_the_mcp_endpoint_gets_401_json_without_the_accept_header(): void
    {
        $this->completeInstanceSetup();

        $response = $this->post('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $response->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_guest_on_a_dashboard_page_still_goes_to_the_login_page(): void
    {
        $this->completeInstanceSetup();

        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }
}
