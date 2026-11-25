<?php

namespace Tests\Feature;

use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\UpdateNewsletterTool;
use App\Models\EmailTemplate;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API and the MCP server write newsletter content through
 * `UpdateNewsletterContent`, the same as the dashboard.
 */
class NewsletterContentChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_update_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->newsletter('scheduled');
        Sanctum::actingAs($user, ['*']);
        $this->claimWhenRead($newsletter);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", ['subject' => 'Changed while it sends'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertNotSame('Changed while it sends', $newsletter->message()->value('subject'));
    }

    public function test_the_api_refuses_an_edit_that_leaves_a_scheduled_newsletter_without_a_body(): void
    {
        [$user, $newsletter] = $this->newsletter('scheduled');
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", ['html_content' => '<p></p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('html_content');
    }

    public function test_the_api_filters_clear_the_segment(): void
    {
        [$user, $newsletter] = $this->newsletterWithSegment();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", ['filters' => $this->listFilter($user)])->assertOk();

        $this->assertNull($newsletter->fresh()->segment_id);
    }

    public function test_the_api_does_not_store_a_slot_without_a_template(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", ['html_content' => '<p>Body</p>'])->assertOk();

        $this->assertNull($newsletter->message()->value('template_slot_content'));
    }

    public function test_the_mcp_update_refuses_a_newsletter_that_became_scheduled_without_confirmation(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');
        $scheduled = false;

        Newsletter::retrieved(function (Newsletter $retrieved) use ($newsletter, &$scheduled): void {
            if ($scheduled || $retrieved->id !== $newsletter->id) {
                return;
            }

            $scheduled = true;
            Newsletter::withoutGlobalScopes()->whereKey($newsletter->id)->update(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        });

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'title' => 'Unconfirmed change',
        ])->assertHasErrors(['scheduled']);

        $this->assertNotSame('Unconfirmed change', Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->title);
    }

    public function test_the_mcp_refuses_an_edit_that_leaves_a_scheduled_newsletter_without_a_body(): void
    {
        [$user, $newsletter] = $this->newsletter('scheduled');

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'html_content' => '<p></p>',
            'confirm_live' => true,
        ])->assertHasErrors(['Add content before you send.']);
    }

    public function test_the_mcp_filters_clear_the_segment(): void
    {
        [$user, $newsletter] = $this->newsletterWithSegment();

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'filters' => $this->listFilter($user),
        ])->assertOk();

        $this->assertNull($newsletter->fresh()->segment_id);
    }

    public function test_the_mcp_stores_the_template_slot(): void
    {
        [$user, $newsletter] = $this->newsletter('draft');
        $template = EmailTemplate::factory()->for($user->client)->create(['html_content' => '<header>H</header>{{ content }}']);

        ByoMailerServer::actingAs($user)->tool(UpdateNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'email_template_id' => $template->id,
            'html_content' => '<p>Body</p>',
        ])->assertOk();

        $this->assertSame('<p>Body</p>', $newsletter->message()->value('template_slot_content'));
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
    private function newsletterWithSegment(): array
    {
        [$user, $newsletter] = $this->newsletter('draft');
        $newsletter->update(['segment_id' => Segment::factory()->for($user->client)->create()->id]);

        return [$user, $newsletter];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listFilter(User $user): array
    {
        return [[
            'connector' => 'and',
            'category' => 'lists',
            'mode' => 'any',
            'values' => [MailList::factory()->for($user)->create()->id],
        ]];
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
