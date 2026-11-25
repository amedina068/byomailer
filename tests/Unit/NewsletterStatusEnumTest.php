<?php

namespace Tests\Unit;

use App\Enums\NewsletterStatus;
use App\Models\Newsletter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterStatusEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stored_values_do_not_change(): void
    {
        $this->assertSame(
            ['draft', 'scheduled', 'queued', 'ab_testing', 'sent', 'failed'],
            array_column(NewsletterStatus::cases(), 'value'),
        );
    }

    public function test_the_api_input_value_live_is_not_a_stored_status(): void
    {
        $this->assertNull(NewsletterStatus::tryFrom('live'));
    }

    public function test_only_a_draft_or_a_scheduled_newsletter_is_mutable(): void
    {
        $this->assertSame([NewsletterStatus::Draft, NewsletterStatus::Scheduled], NewsletterStatus::mutable());

        foreach (NewsletterStatus::cases() as $status) {
            $this->assertSame(in_array($status, NewsletterStatus::mutable(), true), $status->isMutable(), $status->value);
        }
    }

    public function test_a_sent_or_a_failed_newsletter_is_finished(): void
    {
        $this->assertSame(
            [NewsletterStatus::Draft, NewsletterStatus::Scheduled, NewsletterStatus::Queued, NewsletterStatus::AbTesting],
            NewsletterStatus::unfinished(),
        );
    }

    public function test_a_draft_is_not_scheduled_or_sending(): void
    {
        $this->assertSame(
            [NewsletterStatus::Scheduled, NewsletterStatus::Queued, NewsletterStatus::AbTesting],
            NewsletterStatus::scheduledOrSending(),
        );
    }

    public function test_each_status_has_a_label(): void
    {
        $this->assertSame('A/B testing', NewsletterStatus::AbTesting->label());

        foreach (NewsletterStatus::cases() as $status) {
            $this->assertNotEmpty($status->label());
        }
    }

    public function test_the_model_casts_the_status_and_stores_the_string_value(): void
    {
        $newsletter = Newsletter::factory()->create(['status' => NewsletterStatus::AbTesting]);

        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id, 'status' => 'ab_testing']);

        $newsletter->update(['status' => NewsletterStatus::Sent]);

        $this->assertSame(NewsletterStatus::Sent, $newsletter->fresh()->status);
        $this->assertDatabaseHas('newsletters', ['id' => $newsletter->id, 'status' => 'sent']);
    }

    public function test_the_mutable_scope_finds_only_draft_and_scheduled_newsletters(): void
    {
        $ids = collect(NewsletterStatus::cases())
            ->mapWithKeys(fn (NewsletterStatus $status): array => [
                $status->value => Newsletter::factory()->create(['status' => $status])->id,
            ]);

        $this->assertEqualsCanonicalizing(
            [$ids['draft'], $ids['scheduled']],
            Newsletter::query()->withoutGlobalScopes()->mutable()->pluck('id')->all(),
        );
    }
}
