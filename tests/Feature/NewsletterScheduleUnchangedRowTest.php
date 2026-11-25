<?php

namespace Tests\Feature;

use App\Enums\NewsletterStatus;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A schedule change that writes the values that the row already has is not a
 * lost race. MySQL reports 0 affected rows for such an update, and SQLite
 * reports 1. The tests freeze the time, so `updated_at` does not change
 * either. Run them on MySQL to see the difference.
 */
class NewsletterScheduleUnchangedRowTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_unschedule_of_a_draft_with_no_send_time_succeeds(): void
    {
        $this->freezeSecond();
        $newsletter = Newsletter::factory()->for(User::factory())->create(['status' => NewsletterStatus::Draft, 'scheduled_at' => null]);
        $this->actingAsTenant($newsletter->client_id);

        $this->assertTrue(app(NewsletterScheduleService::class)->unschedule($newsletter));
        $this->assertSame(NewsletterStatus::Draft, $this->statusOf($newsletter));
    }

    public function test_the_schedule_at_the_same_send_time_succeeds(): void
    {
        $this->freezeSecond();
        $sendAt = CarbonImmutable::now()->addDay()->startOfMinute();
        [, $newsletter] = $this->scheduledNewsletterWithSender($sendAt);

        $this->assertTrue(app(NewsletterScheduleService::class)->schedule($newsletter, $sendAt));
        $this->assertSame(NewsletterStatus::Scheduled, $this->statusOf($newsletter));
    }

    public function test_the_api_draft_status_on_a_draft_succeeds(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['status' => NewsletterStatus::Draft, 'scheduled_at' => null]);
        Sanctum::actingAs($user, ['*']);

        $this->patchJson("/api/v1/newsletters/{$newsletter->id}", ['status' => 'draft', 'subject' => 'New subject'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subject', 'New subject');
    }

    public function test_the_web_schedule_step_saved_again_with_the_same_time_succeeds(): void
    {
        Bus::fake();
        $this->freezeSecond();
        $sendAt = CarbonImmutable::now()->addDay()->startOfMinute();
        [$user, $newsletter, $identity] = $this->scheduledNewsletterWithSender($sendAt);
        $timezone = $user->client->timezone ?? 'UTC';

        $this->actingAs($user)
            ->from(route('newsletters.schedule.show', $newsletter))
            ->post(route('newsletters.schedule', $newsletter), [
                'action' => 'schedule',
                'scheduled_for' => $sendAt->setTimezone($timezone)->format('Y-m-d\TH:i'),
                'email_identity_id' => $identity->id,
            ])
            ->assertRedirect(route('newsletters.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('error');

        $this->assertSame(NewsletterStatus::Scheduled, $this->statusOf($newsletter));
    }

    /**
     * @return array{User, Newsletter, EmailIdentity}
     */
    private function scheduledNewsletterWithSender(CarbonImmutable $sendAt): array
    {
        $user = User::factory()->create();
        $identity = EmailIdentity::factory()->for($user)->create();
        $newsletter = Newsletter::factory()->for($user)->scheduled($sendAt)->create();

        $this->actingAsTenant($newsletter->client_id);
        $newsletter->message->forceFill([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
        ])->save();

        return [$user, $newsletter->refresh(), $identity];
    }

    private function statusOf(Newsletter $newsletter): NewsletterStatus
    {
        return Newsletter::withoutGlobalScopes()->findOrFail($newsletter->id)->status;
    }
}
