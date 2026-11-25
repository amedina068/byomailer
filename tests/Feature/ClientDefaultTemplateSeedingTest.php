<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\SeedClientDefaultTemplate;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Scopes\ClientScope;
use App\Support\DefaultTemplateContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDefaultTemplateSeedingTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_action_creates_client_default_template(): void
    {
        $client = Client::factory()->create();
        User::factory()->for($client)->create();

        $action = app(SeedClientDefaultTemplate::class);
        $template = $action->handle($client);

        $this->assertNotNull($template);
        $this->assertTrue($template->is_default);
        $this->assertSame($client->id, $template->client_id);
        $this->assertStringContainsString('{{ content }}', $template->html_content);
        $this->assertStringContainsString('{{ unsubscribe_url }}', $template->html_content);
        $this->assertStringContainsString('{{ postal_address }}', $template->html_content);
    }

    public function test_seed_action_is_idempotent(): void
    {
        $client = Client::factory()->create();
        User::factory()->for($client)->create();

        $action = app(SeedClientDefaultTemplate::class);
        $first = $action->handle($client);
        $second = $action->handle($client);

        $this->assertSame($first->id, $second->id);

        $count = EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->where('client_id', $client->id)
            ->where('is_default', true)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_registration_creates_default_template_for_new_client(): void
    {
        config(['app.registration_enabled' => true]);

        $action = app(CreateNewUser::class);

        $user = $action->create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password-very-long-123',
            'password_confirmation' => 'password-very-long-123',
            'timezone' => 'UTC',
            'terms' => '1',
        ]);

        $defaultTemplate = EmailTemplate::withoutGlobalScopes()
            ->where('client_id', $user->client_id)
            ->where('is_default', true)
            ->first();

        $this->assertNotNull($defaultTemplate);
        $this->assertSame(DefaultTemplateContent::NAME, $defaultTemplate->name);
    }
}
