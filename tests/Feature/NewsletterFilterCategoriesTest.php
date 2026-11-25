<?php

namespace Tests\Feature;

use App\Models\ContactAttribute;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NewsletterFilterCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_filter_is_saved(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    ['connector' => 'and', 'category' => 'date', 'operator' => 'in_last', 'values' => ['days' => 30]],
                ],
            ])
            ->assertRedirect(route('newsletters.preview.show', $newsletter));

        $newsletter->refresh();

        $this->assertSame('date', $newsletter->filters[0]['category']);
        $this->assertSame('in_last', $newsletter->filters[0]['operator']);
        $this->assertSame(30, $newsletter->filters[0]['values']['days']);
    }

    public function test_engagement_filter_is_saved(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        $target = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'engagement',
                        'operator' => 'not_opened',
                        'values' => ['newsletter_id' => $target->id, 'within' => 'ever', 'days' => null],
                    ],
                ],
            ])
            ->assertRedirect(route('newsletters.preview.show', $newsletter));

        $newsletter->refresh();

        $this->assertSame('engagement', $newsletter->filters[0]['category']);
        $this->assertSame($target->id, $newsletter->filters[0]['values']['newsletter_id']);
    }

    public function test_location_filter_is_saved(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    ['connector' => 'and', 'category' => 'location', 'field' => 'country', 'mode' => 'none', 'values' => ['US']],
                ],
            ])
            ->assertRedirect(route('newsletters.preview.show', $newsletter));

        $newsletter->refresh();

        $this->assertSame('location', $newsletter->filters[0]['category']);
        $this->assertSame('country', $newsletter->filters[0]['field']);
        $this->assertSame(['US'], $newsletter->filters[0]['values']);
    }

    public function test_attribute_filter_keeps_operator(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'age']);

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'attributes',
                        'mode' => 'all',
                        'values' => [['key' => 'age', 'operator' => 'gt', 'value' => '21']],
                    ],
                ],
            ])
            ->assertRedirect(route('newsletters.preview.show', $newsletter));

        $newsletter->refresh();

        $this->assertSame('gt', $newsletter->filters[0]['values'][0]['operator']);
    }

    public function test_invalid_date_operator_is_rejected(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    ['connector' => 'and', 'category' => 'date', 'operator' => 'contains', 'values' => ['date' => '2026-01-01']],
                ],
            ])
            ->assertSessionHasErrors('filters.0.operator');
    }

    public function test_date_filter_with_an_impossible_date_is_rejected_and_not_dropped(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create(['filters' => []]);

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    ['connector' => 'and', 'category' => 'date', 'operator' => 'before', 'values' => ['date' => '2026-02-31']],
                ],
            ])
            ->assertSessionHasErrors(['filters.0.values' => 'Enter a valid date in the YYYY-MM-DD format.']);

        $this->assertSame([], $newsletter->refresh()->filters);
    }

    public function test_api_rejects_an_unknown_engagement_window_and_does_not_widen_it_to_ever(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/v1/newsletters', [
            'title' => 'Bad window',
            'status' => 'draft',
            'filters' => [
                ['connector' => 'and', 'category' => 'engagement', 'operator' => 'opened', 'values' => ['within' => 'forever']],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filters.0.values' => 'Choose a valid time window.']);

        $this->assertSame(0, Newsletter::query()->count());
    }

    public function test_invalid_attribute_operator_is_rejected(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();
        ContactAttribute::factory()->for($user)->create(['name' => 'plan']);

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'attributes',
                        'mode' => 'all',
                        'values' => [['key' => 'plan', 'operator' => 'before', 'value' => 'pro']],
                    ],
                ],
            ])
            ->assertSessionHasErrors('filters.0.values');
    }

    public function test_location_filter_with_invalid_field_is_rejected(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    ['connector' => 'and', 'category' => 'location', 'field' => 'planet', 'mode' => 'any', 'values' => ['US']],
                ],
            ])
            ->assertSessionHasErrors('filters.0.field');
    }

    public function test_invalid_engagement_operator_is_rejected(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    ['connector' => 'and', 'category' => 'engagement', 'operator' => 'bounced', 'values' => ['within' => 'ever']],
                ],
            ])
            ->assertSessionHasErrors('filters.0.operator');
    }

    public function test_engagement_filter_with_cross_client_newsletter_is_rejected(): void
    {
        $user = User::factory()->create();
        $newsletter = Newsletter::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $foreignNewsletter = Newsletter::factory()->for($otherUser)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'engagement',
                        'operator' => 'opened',
                        'values' => ['newsletter_id' => $foreignNewsletter->id, 'within' => 'ever', 'days' => null],
                    ],
                ],
            ])
            ->assertSessionHasErrors('filters.0.values');
    }

    public function test_sequence_accepts_engagement_filter(): void
    {
        $user = User::factory()->create();
        $sequence = Sequence::factory()->for($user)->create(['status' => 'draft']);

        $this->actingAs($user)
            ->patch(route('sequences.filters.update', $sequence), [
                'filters' => [
                    [
                        'connector' => 'and',
                        'category' => 'engagement',
                        'operator' => 'not_opened',
                        'values' => ['newsletter_id' => null, 'within' => 'last_n_days', 'days' => 90],
                    ],
                ],
            ])
            ->assertRedirect();

        $sequence->refresh();

        $this->assertSame('engagement', $sequence->filters[0]['category']);
        $this->assertSame(90, $sequence->filters[0]['values']['days']);
    }

    public function test_web_preview_rejects_invalid_operator(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('newsletters.contacts.preview'), [
                'filters' => [
                    ['connector' => 'and', 'category' => 'date', 'operator' => 'contains', 'values' => ['date' => '2026-01-01']],
                ],
            ])
            ->assertStatus(422);
    }
}
