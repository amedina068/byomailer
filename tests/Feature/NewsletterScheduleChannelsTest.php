<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\ScheduleNewsletterTool;
use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterScheduleService;
use App\Services\SendableContentCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Web, API and MCP schedule and send a newsletter through
 * `NewsletterScheduleService`. They read the result of the guarded status
 * change, and they use one sender rule: a verified identity of the client.
 */
class NewsletterScheduleChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_web_schedule_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->dueNewsletter();
        $identity = EmailIdentity::factory()->for($user)->create();
        $this->claimWhenRead($newsletter);

        $this->actingAs($user)
            ->from(route('newsletters.schedule.show', $newsletter))
            ->post(route('newsletters.schedule', $newsletter), [
                'action' => 'schedule',
                'scheduled_for' => now($user->client->timezone)->addDay()->format('Y-m-d\TH:i'),
                'email_identity_id' => $identity->id,
            ])
            ->assertRedirect(route('newsletters.schedule.show', $newsletter))
            ->assertSessionHasErrors('status');

        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($newsletter));
        $this->assertNotSame($identity->id, $newsletter->message()->value('email_identity_id'));
    }

    public function test_the_web_schedule_refuses_a_sender_that_is_not_verified(): void
    {
        [$user, $newsletter] = $this->draftNewsletter();
        $pending = EmailIdentity::factory()->for($user)->pending()->create();

        $this->actingAs($user)
            ->from(route('newsletters.schedule.show', $newsletter))
            ->post(route('newsletters.schedule', $newsletter), [
                'action' => 'schedule',
                'scheduled_for' => now($user->client->timezone)->addDay()->format('Y-m-d\TH:i'),
                'email_identity_id' => $pending->id,
            ])
            ->assertSessionHasErrors('email_identity_id');

        $this->assertSame(NewsletterStatus::Draft, $this->statusOf($newsletter));
        $this->assertNotSame($pending->id, $newsletter->message()->value('email_identity_id'));
    }

    public function test_the_api_schedule_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->dueNewsletter();
        Sanctum::actingAs($user, ['*']);
        $this->claimWhenRead($newsletter);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'title' => 'Changed while it sends',
            'status' => 'live',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($newsletter));
        $this->assertNotSame('Changed while it sends', Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->title);
    }

    public function test_the_api_send_now_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        Bus::fake([DispatchNewsletterBatchJob::class]);
        [$user, $newsletter] = $this->dueNewsletter();
        Sanctum::actingAs($user, ['*']);
        $this->claimWhenRead($newsletter);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'live',
            'scheduled_at' => now()->toIso8601String(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($newsletter));
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_the_api_schedule_returns_the_compliance_warnings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);
        $identity = EmailIdentity::factory()->for($user)->create();

        $this->postJson('/api/v1/newsletters', [
            'subject' => 'Hello',
            'html_content' => '<p>No unsubscribe link</p>',
            'email_identity_id' => $identity->id,
            'status' => 'live',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('meta.warnings', [SendableContentCheck::UNSUBSCRIBE_WARNING]);
    }

    public function test_the_api_schedule_refuses_a_stored_sender_that_is_not_verified(): void
    {
        [$user, $newsletter] = $this->draftNewsletter();
        Sanctum::actingAs($user, ['*']);
        $this->storeSender($newsletter, EmailIdentity::factory()->for($user)->pending()->create());

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'live',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email_identity_id');

        $this->assertSame(NewsletterStatus::Draft, $this->statusOf($newsletter));
    }

    public function test_the_mcp_schedule_reports_a_newsletter_that_the_scheduler_claimed_first(): void
    {
        [$user, $newsletter] = $this->dueNewsletter();
        $this->claimWhenRead($newsletter);

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors(["Newsletter #{$newsletter->id} started to send"]);

        $this->assertSame(NewsletterStatus::Queued, $this->statusOf($newsletter));
    }

    public function test_the_mcp_schedule_refuses_a_sender_that_is_not_verified(): void
    {
        [$user, $newsletter] = $this->draftNewsletter();
        $this->storeSender($newsletter, EmailIdentity::factory()->for($user)->pending()->create());

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors(['verified sender']);

        $this->assertSame(NewsletterStatus::Draft, $this->statusOf($newsletter));
    }

    /**
     * The identity delete can run after `sendCheck()` read the sender and
     * before the status changes. Then the newsletter must not go live.
     *
     * @return array<string, array{string}>
     */
    public static function sendTimeProvider(): array
    {
        return [
            'schedule' => ['+1 day'],
            'send now' => ['now'],
        ];
    }

    #[DataProvider('sendTimeProvider')]
    public function test_the_service_refuses_a_sender_identity_that_was_deleted_after_the_check(string $sendAt): void
    {
        Bus::fake([DispatchNewsletterBatchJob::class]);
        [$user, $newsletter] = $this->draftNewsletter();
        $this->actingAsTenant($user->client_id);
        $newsletter->load('message.emailIdentity');
        EmailIdentity::query()->whereKey($newsletter->message->email_identity_id)->delete();

        try {
            app(NewsletterScheduleService::class)->scheduleOrSend($user, $newsletter, now()->modify($sendAt));
            $this->fail('The schedule did not fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email_identity_id', $exception->errors());
        }

        $this->assertSame(NewsletterStatus::Draft, $this->statusOf($newsletter));
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_the_mcp_schedule_refuses_a_sender_identity_that_was_deleted_after_the_check(): void
    {
        [$user, $newsletter] = $this->draftNewsletter();
        $identityId = Message::withoutGlobalScopes()->where('messageable_id', $newsletter->id)->value('email_identity_id');
        $deleted = false;

        EmailIdentity::retrieved(function (EmailIdentity $identity) use ($identityId, &$deleted): void {
            if ($deleted || $identity->id !== $identityId) {
                return;
            }

            $deleted = true;
            EmailIdentity::withoutGlobalScopes()->whereKey($identityId)->delete();
        });

        ByoMailerServer::actingAs($user)->tool(ScheduleNewsletterTool::class, [
            'newsletter_id' => $newsletter->id,
            'action' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'confirm_live' => true,
        ])->assertHasErrors(['sender identity']);

        $this->assertSame(NewsletterStatus::Draft, $this->statusOf($newsletter));
    }

    /**
     * @return array{User, Newsletter}
     */
    private function draftNewsletter(): array
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft']);

        return [$user, $newsletter];
    }

    /**
     * A scheduled newsletter whose send time passed, so the scheduler can
     * claim it.
     *
     * @return array{User, Newsletter}
     */
    private function dueNewsletter(): array
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->scheduled(now()->subMinute())->create();

        return [$user, $newsletter];
    }

    private function storeSender(Newsletter $newsletter, EmailIdentity $identity): void
    {
        $this->actingAsTenant($newsletter->client_id);

        $newsletter->message->forceFill([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
        ])->save();
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

    private function statusOf(Newsletter $newsletter): NewsletterStatus
    {
        return Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->status;
    }
}
