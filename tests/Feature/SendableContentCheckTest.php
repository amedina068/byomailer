<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Mail\NewsletterMail;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\SendableContentCheck;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SendableContentCheckTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $message
     * @return array{0: User, 1: Newsletter, 2: EmailIdentity}
     */
    private function draftNewsletter(array $message = []): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->withListFilters([$list->id])->create(['status' => 'draft']);
        $newsletter->message()->update(array_merge([
            'email_identity_id' => $identity->id,
            'subject' => 'Monthly update',
            'html_content' => '<p>Hello</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
            'text_content' => 'Hello',
        ], $message));

        return [$user, $newsletter, $identity];
    }

    public function test_web_send_now_with_blank_subject_is_rejected_and_nothing_is_queued(): void
    {
        Bus::fake();
        [$user, $newsletter, $identity] = $this->draftNewsletter(['subject' => '']);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors(['subject' => SendableContentCheck::SUBJECT_MESSAGE]);
        $this->assertSame(NewsletterStatus::Draft, $newsletter->fresh()->status);
        $this->assertNull($newsletter->fresh()->scheduled_at);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_web_schedule_with_whitespace_only_subject_is_rejected(): void
    {
        [$user, $newsletter, $identity] = $this->draftNewsletter(['subject' => '   ']);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'schedule',
            'scheduled_for' => now()->addDay()->setTimezone($user->client->timezone ?? 'UTC')->format('Y-m-d\TH:i'),
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors('subject');
        $this->assertSame(NewsletterStatus::Draft, $newsletter->fresh()->status);
    }

    public function test_web_schedule_with_image_only_html_and_blank_text_is_rejected(): void
    {
        Bus::fake();
        [$user, $newsletter, $identity] = $this->draftNewsletter([
            'html_content' => '<img src="https://example.com/banner.png">',
            'text_content' => '',
        ]);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors(['html_content' => SendableContentCheck::CONTENT_MESSAGE]);
        $response->assertSessionDoesntHaveErrors('subject');
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_web_draft_action_skips_the_content_check(): void
    {
        [$user, $newsletter, $identity] = $this->draftNewsletter(['subject' => '']);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'draft',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('newsletters.index'));
    }

    public function test_web_send_now_with_complete_content_is_queued(): void
    {
        Bus::fake();
        [$user, $newsletter, $identity] = $this->draftNewsletter();

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('newsletters.show', $newsletter));
        $this->assertSame(NewsletterStatus::Queued, $newsletter->fresh()->status);
        Bus::assertDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_api_live_update_with_image_only_html_is_rejected_under_html_content(): void
    {
        [$user, $newsletter, $identity] = $this->draftNewsletter();
        Sanctum::actingAs($user, ['*']);
        Bus::fake();

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'live',
            'scheduled_at' => now()->toDateTimeString(),
            'email_identity_id' => $identity->id,
            'html_content' => '<img src="https://example.com/banner.png">',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['html_content' => SendableContentCheck::CONTENT_MESSAGE]);
    }

    public function test_api_live_update_with_blank_subject_in_request_overrides_stored_subject(): void
    {
        [$user, $newsletter, $identity] = $this->draftNewsletter();
        Sanctum::actingAs($user, ['*']);
        Bus::fake();

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'live',
            'scheduled_at' => now()->toDateTimeString(),
            'email_identity_id' => $identity->id,
            'subject' => '',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->assertSame('Monthly update', $newsletter->message->fresh()->subject);
    }

    public function test_web_send_now_with_image_only_variant_b_is_rejected(): void
    {
        Bus::fake();
        [$user, $newsletter, $identity] = $this->draftNewsletter();
        $newsletter->update(['ab_test_enabled' => true]);
        Message::factory()->for($user)->for($newsletter, 'messageable')->create([
            'variant' => 'b',
            'subject' => 'Version B',
            'html_content' => '<img src="https://example.com/banner.png">',
            'text_content' => '',
        ]);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors(['html_content_b' => SendableContentCheck::CONTENT_B_MESSAGE]);
        $response->assertSessionDoesntHaveErrors(['subject', 'html_content', 'subject_b']);
        $this->assertSame(NewsletterStatus::Draft, $newsletter->fresh()->status);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_web_send_now_without_variant_b_message_is_rejected_when_ab_enabled(): void
    {
        Bus::fake();
        [$user, $newsletter, $identity] = $this->draftNewsletter();
        $newsletter->update(['ab_test_enabled' => true]);

        $response = $this->actingAs($user)->post(route('newsletters.schedule', $newsletter), [
            'action' => 'send_now',
            'email_identity_id' => $identity->id,
        ]);

        $response->assertSessionHasErrors(['subject_b', 'html_content_b']);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_api_live_update_with_blank_variant_b_subject_is_rejected(): void
    {
        [$user, $newsletter, $identity] = $this->draftNewsletter();
        Sanctum::actingAs($user, ['*']);
        Bus::fake();

        $response = $this->patchJson("/api/v1/newsletters/{$newsletter->id}", [
            'status' => 'live',
            'scheduled_at' => now()->toDateTimeString(),
            'email_identity_id' => $identity->id,
            'ab_test_enabled' => true,
            'subject_b' => '',
            'html_content_b' => '<p>Version B</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_b' => SendableContentCheck::SUBJECT_B_MESSAGE])
            ->assertJsonMissingValidationErrors(['subject', 'html_content', 'html_content_b']);
        $this->assertSame(NewsletterStatus::Draft, $newsletter->fresh()->status);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_api_store_live_with_image_only_html_is_rejected(): void
    {
        [$user, , $identity] = $this->draftNewsletter();
        Sanctum::actingAs($user, ['*']);
        Bus::fake();

        $response = $this->postJson('/api/v1/newsletters', [
            'title' => 'Image only',
            'subject' => 'Sale',
            'html_content' => '<img src="https://example.com/banner.png">',
            'status' => 'live',
            'scheduled_at' => now()->toDateTimeString(),
            'email_identity_id' => $identity->id,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['html_content' => SendableContentCheck::CONTENT_MESSAGE]);
        $this->assertDatabaseMissing('newsletters', ['title' => 'Image only']);
        Bus::assertNotDispatched(DispatchNewsletterBatchJob::class);
    }

    public function test_sequence_step_go_live_with_blank_body_is_rejected_under_status(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $sequence = Sequence::factory()->for($user)->create(['status' => 'live']);
        $step = SequenceStep::factory()->for($sequence)->create(['status' => SequenceStatus::Draft]);
        $step->message()->update([
            'subject' => 'Welcome',
            'html_content' => '<p>&nbsp;</p>',
            'text_content' => '',
        ]);

        $response = $this->actingAs($user)->patch(
            route('sequences.steps.status.update', [$sequence, $step]),
            ['status' => 'live'],
        );

        $response->assertSessionHasErrors(['status' => SendableContentCheck::CONTENT_MESSAGE]);
        $this->assertSame(SequenceStatus::Draft, $step->fresh()->status);
    }

    public function test_test_mail_with_blank_subject_still_sends(): void
    {
        [$user, $newsletter] = $this->draftNewsletter(['subject' => '']);
        EmailCredential::factory()->for($user)->create();

        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);
        $mailer->shouldReceive('send')->once()->withArgs(fn (NewsletterMail $mail): bool => true)->andReturnNull();
        $this->app->instance(MailManager::class, $mailManager);

        $response = $this->actingAs($user)->post(route('newsletters.test-mail', $newsletter));

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
    }
}
