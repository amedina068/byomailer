<?php

namespace Tests\Feature\Api;

use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsletterVariablesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_variables_endpoint_returns_detected_vars_and_defaults(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $newsletter = Newsletter::factory()->for($user)->withContentVariables([
            'first_name' => 'Friend',
        ])->create();

        $newsletter->message->update([
            'subject' => 'Hi {{ first_name }}',
            'html_content' => '<p>Hello {{ first_name }} from {{ company }}</p>',
        ]);

        $response = $this->getJson("/api/v1/newsletters/{$newsletter->id}/variables");

        $response->assertOk()
            ->assertJsonPath('data.detected_variables', ['company', 'first_name'])
            ->assertJsonPath('data.content_variables.first_name', 'Friend')
            ->assertJsonPath('data.content_variables.company', '');
    }

    public function test_variables_endpoint_excludes_reserved_names(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message->update([
            'subject' => 'Hi {{ first_name }}',
            'html_content' => '{{ content }} {{ unsubscribe_url }} {{ first_name }}',
        ]);

        $response = $this->getJson("/api/v1/newsletters/{$newsletter->id}/variables");

        $response->assertOk()
            ->assertJsonPath('data.detected_variables', ['first_name']);
    }

    public function test_variables_endpoint_returns_404_for_other_client(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $other = User::factory()->create();
        $newsletter = Newsletter::factory()->for($other)->create();

        $response = $this->getJson("/api/v1/newsletters/{$newsletter->id}/variables");

        $response->assertNotFound();
    }

    public function test_show_keeps_a_saved_default_that_the_content_does_not_use_yet(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->withContentVariables(['checkout_url' => 'https://example.com/checkout'])->create();
        $newsletter->message->update(['subject' => 'Hi {{ first_name }}', 'html_content' => '<p>Hello</p>']);

        $this->getJson("/api/v1/newsletters/{$newsletter->id}/variables")
            ->assertOk()
            ->assertJsonPath('data.detected_variables', ['first_name'])
            ->assertJsonPath('data.content_variables.checkout_url', 'https://example.com/checkout')
            ->assertJsonPath('data.content_variables.first_name', '');
    }

    public function test_update_replaces_the_defaults_of_a_draft(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->withContentVariables(['old' => 'value'])->create(['status' => 'draft']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}/variables", [
            'content_variables' => ['company' => 'Acme', 'first_name' => null],
        ])->assertOk()
            ->assertJsonPath('data.content_variables.company', 'Acme')
            ->assertJsonPath('data.content_variables.first_name', '');

        $this->assertSame(['company' => 'Acme', 'first_name' => ''], $newsletter->refresh()->content_variables);
    }

    public function test_update_changes_a_scheduled_newsletter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create();

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}/variables", [
            'content_variables' => ['company' => 'Acme'],
        ])->assertOk();

        $this->assertSame(['company' => 'Acme'], $newsletter->refresh()->content_variables);
    }

    public function test_update_refuses_a_sent_newsletter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->withContentVariables(['company' => 'Old'])->create(['status' => 'sent']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}/variables", [
            'content_variables' => ['company' => 'New'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => "Newsletters with status 'sent' cannot be modified."]);

        $this->assertSame(['company' => 'Old'], $newsletter->refresh()->content_variables);
    }

    public function test_update_rejects_a_default_longer_than_the_dashboard_allows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->withContentVariables(['company' => 'Old'])->create(['status' => 'draft']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}/variables", [
            'content_variables' => ['company' => str_repeat('a', 256)],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['content_variables.company' => 'must not be greater than 255 characters']);

        $this->assertSame(['company' => 'Old'], $newsletter->refresh()->content_variables);
    }

    public function test_update_returns_404_for_another_client(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->withContentVariables(['company' => 'Old'])->create(['status' => 'draft']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}/variables", ['content_variables' => []])->assertNotFound();

        $this->assertSame(['company' => 'Old'], $newsletter->refresh()->content_variables);
    }

    public function test_newsletter_update_endpoint_stores_an_empty_default_as_an_empty_string(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'content_variables' => ['company' => null],
        ])->assertOk();

        $this->assertSame(['company' => ''], $newsletter->refresh()->content_variables);
    }
}
