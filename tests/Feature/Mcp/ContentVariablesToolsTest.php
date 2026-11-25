<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateNewsletterTool;
use App\Mcp\Tools\CreateSequenceTool;
use App\Mcp\Tools\NewsletterContentTool;
use App\Mcp\Tools\SequenceContentTool;
use App\Mcp\Tools\UpdateNewsletterTool;
use App\Mcp\Tools\UpdateSequenceTool;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Tests\TestCase;

class ContentVariablesToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_sequence_saves_content_variables(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Limit flow',
            'content_variables' => ['checkout_url' => 'https://example.com/checkout', 'first_name' => null],
        ])->assertOk()->assertSee('checkout_url');

        $sequence = Sequence::query()->sole();
        $this->assertSame(['checkout_url' => 'https://example.com/checkout', 'first_name' => ''], $sequence->content_variables);
    }

    public function test_create_sequence_rejects_a_default_longer_than_the_dashboard_allows(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSequenceTool::class, [
            'name' => 'Limit flow',
            'content_variables' => ['checkout_url' => str_repeat('a', 256)],
        ])->assertHasErrors(['The content_variables.checkout_url field must not be greater than 255 characters.']);

        $this->assertSame(0, Sequence::query()->count());
    }

    public function test_update_sequence_replaces_content_variables_and_keeps_them_when_omitted(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->withContentVariables(['old' => 'value'])->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'name' => 'Renamed',
        ])->assertOk();

        $this->assertSame(['old' => 'value'], $sequence->refresh()->content_variables);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'content_variables' => ['checkout_url' => 'https://example.com/checkout'],
        ])->assertOk()->assertSee('checkout_url');

        $this->assertSame(['checkout_url' => 'https://example.com/checkout'], $sequence->refresh()->content_variables);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'content_variables' => [],
        ])->assertOk();

        $this->assertSame([], $sequence->refresh()->content_variables);
    }

    public function test_update_sequence_asks_for_confirmation_before_changing_variables_of_a_live_sequence(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->withContentVariables(['checkout_url' => 'old'])->create(['status' => 'live']);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'content_variables' => ['checkout_url' => 'new'],
        ])->assertHasErrors(["Sequence #{$sequence->id} is live."]);

        $this->assertSame(['checkout_url' => 'old'], $sequence->refresh()->content_variables);

        ByoMailerServer::actingAs($user)->tool(UpdateSequenceTool::class, [
            'sequence_id' => $sequence->id,
            'content_variables' => ['checkout_url' => 'new'],
            'confirm_live' => true,
        ])->assertOk();

        $this->assertSame(['checkout_url' => 'new'], $sequence->refresh()->content_variables);
    }

    public function test_sequence_content_returns_saved_and_detected_variables(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->withContentVariables(['checkout_url' => 'https://example.com/checkout'])->create();
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message->update([
            'subject' => 'Hi {{ first_name }}',
            'html_content' => '<a href="{{ checkout_url }}">Buy</a> {{ unsubscribe_url }}',
        ]);

        ByoMailerServer::actingAs($user)->tool(SequenceContentTool::class, ['sequence_id' => $sequence->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('content_variables', ['checkout_url' => 'https://example.com/checkout'])
                ->where('detected_variables', ['checkout_url', 'first_name'])
                ->etc());
    }

    public function test_create_and_update_tools_advertise_content_variables_in_their_schema(): void
    {
        $factory = new JsonSchemaTypeFactory;

        $this->assertArrayHasKey('content_variables', app(CreateNewsletterTool::class)->schema($factory));
        $this->assertArrayHasKey('content_variables', app(UpdateNewsletterTool::class)->schema($factory));
        $this->assertArrayHasKey('content_variables', app(CreateSequenceTool::class)->schema($factory));
        $this->assertArrayHasKey('content_variables', app(UpdateSequenceTool::class)->schema($factory));
    }

    public function test_newsletter_content_returns_detected_variables(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->withContentVariables(['company' => 'Acme'])->create();
        $newsletter->message->update(['subject' => 'News from {{ company }}', 'html_content' => '<p>Hi {{ first_name }}</p>']);

        ByoMailerServer::actingAs($user)->tool(NewsletterContentTool::class, ['newsletter_id' => $newsletter->id])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('content_variables', ['company' => 'Acme'])
                ->where('detected_variables', ['company', 'first_name'])
                ->etc());
    }

    public function test_update_newsletter_stores_an_empty_default_as_an_empty_string(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'content_variables' => ['company' => null, 'first_name' => 'Friend'],
        ])->assertOk();

        $this->assertSame(['company' => '', 'first_name' => 'Friend'], $newsletter->refresh()->content_variables);
    }
}
