<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SegmentManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validFilters(): array
    {
        return [['connector' => 'and', 'category' => 'date', 'operator' => 'in_last', 'values' => ['days' => 30]]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     */
    private function attributeFilters(string $value = 'pro'): array
    {
        return [['connector' => 'and', 'category' => 'attributes', 'mode' => 'all', 'values' => [
            ['key' => 'plan', 'operator' => 'equals', 'value' => $value],
        ]]];
    }

    // ---- T-010 CRUD + tenancy ----

    public function test_user_can_create_a_segment(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('segments.store'), [
                'name' => 'Recent signups',
                'description' => 'Joined in the last month',
                'filters' => $this->validFilters(),
            ])
            ->assertRedirect(route('segments.index'));

        $this->assertDatabaseHas('segments', [
            'client_id' => $user->client_id,
            'name' => 'Recent signups',
            'description' => 'Joined in the last month',
        ]);
    }

    public function test_segment_show_lists_matching_contacts_with_both_counts(): void
    {
        $user = User::factory()->create();
        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($user)->unsubscribed()->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);

        $segment = Segment::factory()->withFilters($this->attributeFilters())->create(['client_id' => $user->client_id]);

        $this->actingAs($user)
            ->get(route('segments.show', $segment))
            ->assertInertia(fn (Assert $page) => $page
                ->component('segments/Show')
                ->where('matchingCount', 2)   // includes the unsubscribed pro contact
                ->where('eligibleCount', 1)   // excludes unsubscribed
            );
    }

    public function test_user_cannot_view_edit_or_archive_another_clients_segment(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Segment::factory()->create(['client_id' => $other->client_id]);

        $this->actingAs($user)->get(route('segments.show', $foreign))->assertNotFound();
        $this->actingAs($user)->put(route('segments.update', $foreign), [
            'name' => 'x', 'filters' => $this->validFilters(),
        ])->assertNotFound();
        $this->actingAs($user)->patch(route('segments.archive', $foreign), ['archived' => true])->assertNotFound();
    }

    // ---- T-009 validation ----

    public function test_segment_requires_at_least_one_filter(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('segments.store'), ['name' => 'Empty', 'filters' => []])
            ->assertSessionHasErrors('filters');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function emptyNewFilters(): array
    {
        return [
            'attribute with no key' => [
                ['category' => 'attributes', 'mode' => 'all', 'values' => [['key' => '', 'operator' => 'equals', 'value' => '']]],
                'Choose an attribute for this filter.',
            ],
            'list with no list' => [
                ['category' => 'lists', 'mode' => 'any', 'values' => [0]],
                'Choose at least one list.',
            ],
            'topic with no topic' => [
                ['category' => 'topics', 'mode' => 'any', 'values' => [0]],
                'Choose at least one topic.',
            ],
            'tag with no tag' => [
                ['category' => 'tags', 'mode' => 'any', 'values' => ['']],
                'Choose at least one tag.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    #[DataProvider('emptyNewFilters')]
    public function test_an_empty_new_filter_gets_a_plain_error_on_its_row(array $filter, string $message): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('segments.store'), [
                'name' => 'New segment',
                'filters' => [...$this->validFilters(), ['connector' => 'and', ...$filter]],
            ])
            ->assertSessionHasErrors(['filters.1.values' => $message]);
    }

    public function test_segment_name_must_be_unique_per_client(): void
    {
        $user = User::factory()->create();
        Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'VIPs']);

        $this->actingAs($user)
            ->post(route('segments.store'), ['name' => 'VIPs', 'filters' => $this->validFilters()])
            ->assertSessionHasErrors('name');
    }

    public function test_other_client_may_reuse_a_segment_name(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Segment::factory()->create(['client_id' => $other->client_id, 'name' => 'VIPs']);

        $this->actingAs($user)
            ->post(route('segments.store'), ['name' => 'VIPs', 'filters' => $this->validFilters()])
            ->assertSessionHasNoErrors();
    }

    public function test_segment_may_keep_its_own_name_on_update(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'VIPs']);

        $this->actingAs($user)
            ->put(route('segments.update', $segment), ['name' => 'VIPs', 'filters' => $this->validFilters()])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('segments.show', $segment));
    }

    // ---- T-012 segment_id ownership on targeting requests ----

    public function test_newsletter_rejects_a_foreign_segment_id(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Segment::factory()->create(['client_id' => $other->client_id]);
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), ['filters' => [], 'segment_id' => $foreign->id])
            ->assertSessionHasErrors('segment_id');
    }

    public function test_newsletter_accepts_own_segment_id(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $newsletter = Newsletter::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), ['filters' => [], 'segment_id' => $segment->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($segment->id, $newsletter->fresh()->segment_id);
    }

    public function test_selecting_a_segment_clears_any_inline_filters(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $newsletter = Newsletter::factory()->for($user)->create(['filters' => $this->validFilters()]);

        // Submit a segment AND stale custom filters together — the segment wins
        // and the inline filters must be cleared (exclusive targeting).
        $this->actingAs($user)
            ->patch(route('newsletters.filters.update', $newsletter), [
                'segment_id' => $segment->id,
                'filters' => $this->validFilters(),
            ])
            ->assertSessionHasNoErrors();

        $newsletter->refresh();
        $this->assertSame($segment->id, $newsletter->segment_id);
        $this->assertSame([], $newsletter->filters);
    }

    public function test_sequence_rejects_a_foreign_segment_id(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Segment::factory()->create(['client_id' => $other->client_id]);
        $sequence = Sequence::factory()->for($user)->create();

        $this->actingAs($user)
            ->patch(route('sequences.filters.update', $sequence), ['filters' => [], 'segment_id' => $foreign->id])
            ->assertSessionHasErrors('segment_id');
    }

    // ---- archive / restore ----

    public function test_archiving_a_segment_keeps_referencers_pointed_at_the_live_reference(): void
    {
        $user = User::factory()->create();
        $filters = $this->validFilters();
        $segment = Segment::factory()->withFilters($filters)->create(['client_id' => $user->client_id]);

        $newsletter = Newsletter::factory()->for($user)->create(['status' => 'draft', 'filters' => [], 'segment_id' => $segment->id]);
        $sequence = Sequence::factory()->for($user)->create(['filters' => [], 'segment_id' => $segment->id]);

        $this->actingAs($user)
            ->patch(route('segments.archive', $segment), ['archived' => true])
            ->assertRedirect();

        $this->assertNotNull($segment->fresh()->archived_at);

        // References are untouched — the segment still resolves their audience.
        $newsletter->refresh();
        $this->assertSame($segment->id, $newsletter->segment_id);
        $this->assertSame([], $newsletter->filters);
        $this->assertSameJsonData($filters, $newsletter->effectiveFilters());

        $sequence->refresh();
        $this->assertSame($segment->id, $sequence->segment_id);
        $this->assertSameJsonData($filters, $sequence->effectiveFilters());

        $this->assertDatabaseHas('segments', ['id' => $segment->id]);
    }

    public function test_restoring_a_segment_clears_archived_at(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id, 'archived_at' => now()]);

        $this->actingAs($user)
            ->patch(route('segments.archive', $segment), ['archived' => false])
            ->assertRedirect();

        $this->assertNull($segment->fresh()->archived_at);
    }

    public function test_index_hides_archived_segments_by_default_and_lists_them_when_requested(): void
    {
        $user = User::factory()->create();
        $active = Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Active one']);
        $archived = Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Archived one', 'archived_at' => now()]);

        $this->actingAs($user)
            ->get(route('segments.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('segments/Index')
                ->where('showingArchived', false)
                ->where('activeCount', 1)
                ->where('archivedCount', 1)
                ->where('segments', fn ($segments) => collect($segments)->pluck('id')->all() === [$active->id])
            );

        $this->actingAs($user)
            ->get(route('segments.index', ['archived' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('showingArchived', true)
                ->where('segments', fn ($segments) => collect($segments)->pluck('id')->all() === [$archived->id])
            );
    }

    public function test_archived_segment_is_excluded_from_pickers_unless_currently_selected(): void
    {
        $user = User::factory()->create();
        $archived = Segment::factory()->create(['client_id' => $user->client_id, 'archived_at' => now()]);

        // A fresh newsletter that does not reference the archived segment.
        $newsletter = Newsletter::factory()->for($user)->create(['segment_id' => null]);

        $this->actingAs($user)
            ->get(route('newsletters.filters.show', $newsletter))
            ->assertInertia(fn (Assert $page) => $page
                ->where('segments', fn ($segments) => collect($segments)->pluck('id')->doesntContain($archived->id))
            );

        // A newsletter already targeting it still sees it so the editor renders the selection.
        $referencing = Newsletter::factory()->for($user)->create(['segment_id' => $archived->id, 'filters' => []]);

        $this->actingAs($user)
            ->get(route('newsletters.filters.show', $referencing))
            ->assertInertia(fn (Assert $page) => $page
                ->where('segments', fn ($segments) => collect($segments)->pluck('id')->contains($archived->id))
            );
    }
}
