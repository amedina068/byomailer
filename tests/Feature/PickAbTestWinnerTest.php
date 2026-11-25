<?php

namespace Tests\Feature;

use App\Actions\PickAbTestWinner;
use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Events\NewsletterSent;
use App\Http\Requests\NewsletterAbWinnerRequest;
use App\Models\Newsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PickAbTestWinnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_the_winner_marks_the_newsletter_sent_and_fires_newsletter_sent_one_time(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->withAbTest()->create(['status' => 'ab_testing']);
        $this->actingAsTenant($newsletter->client_id);

        $picked = (new PickAbTestWinner)->handle($newsletter, AbVariant::B);

        $this->assertSame(AbVariant::B, $picked->ab_test_winner_variant);
        $this->assertSame(NewsletterStatus::Sent, $picked->status);
        $this->assertNotNull($picked->sent_at);
        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id, 'status' => 'sent', 'ab_test_winner_variant' => 'b']);
        Event::assertDispatchedTimes(NewsletterSent::class, 1);
        Event::assertDispatched(NewsletterSent::class, fn (NewsletterSent $event): bool => $event->newsletter->is($newsletter)
            && $event->newsletter->status === NewsletterStatus::Sent);
    }

    public function test_it_refuses_a_newsletter_that_is_not_in_ab_testing(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->withAbTest()->create(['status' => 'queued']);
        $this->actingAsTenant($newsletter->client_id);

        $this->assertRefused(fn () => (new PickAbTestWinner)->handle($newsletter, AbVariant::A), 'status');

        $this->assertSame(NewsletterStatus::Queued, $newsletter->fresh()->status);
        $this->assertNull($newsletter->fresh()->ab_test_winner_variant);
        Event::assertNotDispatched(NewsletterSent::class);
    }

    public function test_it_refuses_a_newsletter_without_a_b_variant(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->create(['status' => 'ab_testing', 'ab_test_enabled' => true]);
        $this->actingAsTenant($newsletter->client_id);

        $this->assertRefused(fn () => (new PickAbTestWinner)->handle($newsletter, AbVariant::A), 'ab_test_enabled');

        $this->assertSame(NewsletterStatus::AbTesting, $newsletter->fresh()->status);
        Event::assertNotDispatched(NewsletterSent::class);
    }

    public function test_the_request_refuses_an_unknown_variant(): void
    {
        $rules = (new NewsletterAbWinnerRequest)->rules();

        $this->assertTrue(Validator::make(['winner_variant' => 'c'], $rules)->fails());
        $this->assertTrue(Validator::make(['winner_variant' => 'A'], $rules)->fails());
        $this->assertTrue(Validator::make(['winner_variant' => 'b'], $rules)->passes());
    }

    public function test_a_second_pick_is_refused_and_does_not_fire_again(): void
    {
        Event::fake([NewsletterSent::class]);
        $newsletter = Newsletter::factory()->withAbTest()->create(['status' => 'ab_testing']);
        $this->actingAsTenant($newsletter->client_id);

        (new PickAbTestWinner)->handle($newsletter, AbVariant::A);

        $this->assertRefused(fn () => (new PickAbTestWinner)->handle($newsletter->fresh(), AbVariant::B), 'status');

        $this->assertSame(AbVariant::A, $newsletter->fresh()->ab_test_winner_variant);
        Event::assertDispatchedTimes(NewsletterSent::class, 1);
    }

    private function assertRefused(callable $pick, string $errorKey): void
    {
        try {
            $pick();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());

            return;
        }

        $this->fail("The pick was not refused with a '{$errorKey}' error.");
    }
}
