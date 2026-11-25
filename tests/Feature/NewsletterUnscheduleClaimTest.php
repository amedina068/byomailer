<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\ScheduleNewsletterTool;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The scheduler can queue a scheduled newsletter after a request reads it and
 * before the request returns it to draft. Then the newsletter stays queued,
 * and each channel tells the user that the unschedule failed.
 */
class NewsletterUnscheduleClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_web_unschedule_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->scheduledNewsletter();
        $this->claimWhenRead($newsletter);

        $this->actingAs($user)
            ->from(route('newsletters.show', $newsletter))
            ->delete(route('newsletters.unschedule', $newsletter))
            ->assertRedirect(route('newsletters.show', $newsletter))
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertStillQueued($newsletter);
    }

    public function test_the_schedule_form_draft_action_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->scheduledNewsletter();
        $identity = EmailIdentity::factory()->for($user)->create();
        $this->claimWhenRead($newsletter);

        $this->actingAs($user)
            ->from(route('newsletters.schedule.show', $newsletter))
            ->post(route('newsletters.schedule', $newsletter), [
                'action' => 'draft',
                'email_identity_id' => $identity->id,
            ])
            ->assertRedirect(route('newsletters.schedule.show', $newsletter))
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        $this->assertStillQueued($newsletter);
        $this->assertNotSame($identity->id, $newsletter->message()->value('email_identity_id'));
    }

    public function test_the_api_draft_status_clears_the_send_time_of_a_scheduled_newsletter(): void
    {
        [$user, $newsletter] = $this->scheduledNewsletter();
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", ['status' => 'draft'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.scheduled_at', null);

        $stored = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);
        $this->assertSame(NewsletterStatus::Draft, $stored->status);
        $this->assertNull($stored->scheduled_at);
    }

    public function test_the_api_draft_status_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->scheduledNewsletter();
        Sanctum::actingAs($user, ['*']);
        $this->claimWhenRead($newsletter);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'draft',
            'title' => 'Changed while it sends',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertStillQueued($newsletter);
        $this->assertNotSame('Changed while it sends', Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->title);
    }

    public function test_the_mcp_unschedule_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->scheduledNewsletter();
        $this->claimWhenRead($newsletter);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'unschedule',
            'confirm_live' => true,
        ])->assertHasErrors(["Newsletter #{$newsletter->id} started to send"]);

        $this->assertStillQueued($newsletter);
    }

    /**
     * @return array{User, Newsletter}
     */
    private function scheduledNewsletter(): array
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create();

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

    private function assertStillQueued(Newsletter $newsletter): void
    {
        $stored = Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id);

        $this->assertSame(NewsletterStatus::Queued, $stored->status);
        $this->assertNotNull($stored->scheduled_at);
    }
}
