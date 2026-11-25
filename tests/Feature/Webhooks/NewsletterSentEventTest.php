<?php

namespace Tests\Feature\Webhooks;

use App\Actions\PickAbTestWinner;
use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Events\NewsletterSent;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsletterSentEventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The batch callback runs in the worker of the last newsletter job, and
     * that job carries the client of the newsletter.
     */
    private function finalize(Newsletter $newsletter): void
    {
        Tenant::run($newsletter->client_id, fn () => (new DispatchNewsletterBatchJob($newsletter))->finalizeNewsletter($newsletter->fresh()));
    }

    public function test_non_ab_completion_dispatches_newsletter_sent(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->create(['status' => NewsletterStatus::Queued, 'ab_test_enabled' => false]);

        $this->finalize($newsletter);

        $this->assertSame(NewsletterStatus::Sent, $newsletter->fresh()->status);
        Event::assertDispatched(NewsletterSent::class, fn ($e) => $e->newsletter->is($newsletter));
    }

    public function test_ab_completion_does_not_dispatch(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->create(['status' => NewsletterStatus::Queued, 'ab_test_enabled' => true]);

        $this->finalize($newsletter);

        $this->assertSame(NewsletterStatus::AbTesting, $newsletter->fresh()->status);
        Event::assertNotDispatched(NewsletterSent::class);
    }

    public function test_does_not_double_fire_when_already_sent(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->create(['status' => 'sent', 'ab_test_enabled' => false]);

        $this->finalize($newsletter);

        Event::assertNotDispatched(NewsletterSent::class);
    }

    public function test_the_dashboard_winner_pick_fires_newsletter_sent_one_time(): void
    {
        Event::fake([NewsletterSent::class]);
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->withAbTest()->create(['status' => 'ab_testing']);

        $this->actingAs($user)->post(route('newsletters.ab-winner', $newsletter), ['winner_variant' => 'b']);
        $this->actingAs($user)->post(route('newsletters.ab-winner', $newsletter), ['winner_variant' => 'a'])->assertSessionHasErrors('status');

        $this->assertSame(AbVariant::B, $newsletter->fresh()->ab_test_winner_variant);
        Event::assertDispatchedTimes(NewsletterSent::class, 1);
        Event::assertDispatched(NewsletterSent::class, fn ($event) => $event->newsletter->is($newsletter)
            && $event->newsletter->status === NewsletterStatus::Sent
            && $event->newsletter->sent_at !== null);
    }

    public function test_the_api_winner_pick_fires_newsletter_sent_one_time(): void
    {
        Event::fake([NewsletterSent::class]);
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->withAbTest()->create(['status' => 'ab_testing']);
        Sanctum::actingAs($user, ['*']);

        $this->postJson("/api/v1/newsletters/{$newsletter->id}/ab-winner", ['winner_variant' => 'a'])->assertOk();
        $this->postJson("/api/v1/newsletters/{$newsletter->id}/ab-winner", ['winner_variant' => 'b'])->assertUnprocessable();

        $this->assertSame(AbVariant::A, $newsletter->fresh()->ab_test_winner_variant);
        Event::assertDispatchedTimes(NewsletterSent::class, 1);
    }

    public function test_a_second_pick_of_a_stale_newsletter_does_not_fire_again(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->withAbTest()->create(['status' => 'ab_testing']);
        $this->actingAsTenant($newsletter->client_id);
        $staleCopy = Newsletter::query()->find($newsletter->id);

        (new PickAbTestWinner)->handle($newsletter, AbVariant::A);

        try {
            (new PickAbTestWinner)->handle($staleCopy, AbVariant::B);
            $this->fail('The pick from the stale copy was not refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(AbVariant::A, $newsletter->fresh()->ab_test_winner_variant);
        Event::assertDispatchedTimes(NewsletterSent::class, 1);
    }
}
