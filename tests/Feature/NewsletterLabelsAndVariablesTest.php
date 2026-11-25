<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateNewsletterTool;
use App\Mcp\Tools\UpdateNewsletterTool;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The title and the projects are labels: they change in any status, in web
 * and API. Content variables change only in draft and scheduled, with one
 * key rule in every channel. A new draft gets one default title.
 */
class NewsletterLabelsAndVariablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_web_changes_the_projects_of_a_sent_newsletter(): void
    {
        [$user, $newsletter] = $this->newsletter('sent');
        $project = Project::factory()->for($user->client)->create();

        $this->actingAs($user)
            ->post(route('newsletters.projects.sync', $newsletter), ['project_ids' => [$project->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$project->id], $newsletter->projects()->pluck('projects.id')->all());
    }

    public function test_the_web_renames_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->newsletter('scheduled');
        $this->claimWhenRead($newsletter);

        $this->actingAs($user)
            ->patch(route('newsletters.title.update', $newsletter), ['title' => 'Renamed while it sends'])
            ->assertSessionHasNoErrors();

        $stored = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);
        $this->assertSame('Renamed while it sends', $stored->title);
        $this->assertSame(NewsletterStatus::Queued, $stored->status);
    }

    public function test_the_api_renames_and_files_a_sent_newsletter(): void
    {
        [$user, $newsletter] = $this->newsletter('sent');
        $project = Project::factory()->for($user->client)->create();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'title' => 'Renamed after send',
            'project_ids' => [$project->id],
        ])->assertOk()->assertJsonPath('data.title', 'Renamed after send');

        $this->assertSame([$project->id], $newsletter->projects()->pluck('projects.id')->all());
        $this->assertSame(NewsletterStatus::Sent, $newsletter->fresh()->status);
    }

    public function test_the_api_refuses_a_content_change_to_a_sent_newsletter_with_its_title(): void
    {
        [$user, $newsletter] = $this->newsletter('sent');
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'title' => 'Renamed after send',
            'subject' => 'Changed after send',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertNotSame('Renamed after send', $newsletter->fresh()->title);
    }

    public function test_the_web_refuses_variables_of_a_sent_newsletter(): void
    {
        [$user, $newsletter] = $this->newsletter('sent');

        $this->actingAs($user)
            ->patch(route('newsletters.variables.update', $newsletter), ['content_variables' => ['company' => 'Acme']])
            ->assertNotFound();

        $this->assertNull($newsletter->fresh()->content_variables);
    }

    public function test_the_web_keeps_every_variable_sent_and_fills_each_detected_variable(): void
    {
        [$user, $newsletter] = $this->newsletterWithVariables();

        $this->actingAs($user)
            ->patch(route('newsletters.variables.update', $newsletter), [
                'content_variables' => ['checkout_url' => 'https://example.com'],
            ])
            ->assertRedirect(route('newsletters.filters.show', $newsletter));

        $this->assertSame(['checkout_url' => 'https://example.com', 'first_name' => ''], $newsletter->fresh()->content_variables);
    }

    public function test_the_api_keeps_every_variable_sent_and_fills_each_detected_variable(): void
    {
        [$user, $newsletter] = $this->newsletterWithVariables();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}/variables", [
            'content_variables' => ['checkout_url' => 'https://example.com'],
        ])->assertOk();

        $this->assertSame(['checkout_url' => 'https://example.com', 'first_name' => ''], $newsletter->fresh()->content_variables);
    }

    public function test_the_mcp_keeps_every_variable_sent_and_fills_each_detected_variable(): void
    {
        [$user, $newsletter] = $this->newsletterWithVariables();

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'content_variables' => ['checkout_url' => 'https://example.com'],
        ])->assertOk();

        $this->assertSame(['checkout_url' => 'https://example.com', 'first_name' => ''], $newsletter->fresh()->content_variables);
    }

    public function test_every_channel_gives_a_new_draft_the_same_title(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('newsletters.create'))->assertRedirect();

        Sanctum::actingAs($user, ['*']);
        $this->postJson('/api/v1/newsletters', ['subject' => 'API'])->assertCreated();

        ByoMailerServer::actingAs($user)->tool(CreateNewsletterTool::class, ['subject' => 'MCP'])->assertOk();

        $this->assertSame(
            [Newsletter::DEFAULT_TITLE],
            Newsletter::withoutGlobalScopes()->where('user_id', $user->id)->distinct()->pluck('title')->all(),
        );
    }

    /**
     * @return array{User, Newsletter}
     */
    private function newsletter(string $status): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => $status,
            'scheduled_at' => $status === 'draft' ? null : now()->addDay(),
        ]);

        return [$user, $newsletter];
    }

    /**
     * @return array{User, Newsletter}
     */
    private function newsletterWithVariables(): array
    {
        [$user, $newsletter] = $this->newsletter('draft');
        $newsletter->message->update(['subject' => 'Hi {{ first_name }}']);

        return [$user, $newsletter];
    }

    /**
     * Run the claim of `contact-mails:send-due-newsletters` one time, right
     * after the request reads the newsletter.
     */
    private function claimWhenRead(Newsletter $newsletter): void
    {
        $claimed = false;

        Newsletter::retrieved(function (Newsletter $retrieved) use ($newsletter, &$claimed): void {
            if ($claimed || $retrieved->id !== $newsletter->id) {
                return;
            }

            $claimed = true;

            Newsletter::withoutGlobalScopes()
                ->whereKey($newsletter->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'queued']);
        });
    }
}
