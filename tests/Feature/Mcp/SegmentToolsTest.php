<?php

namespace Tests\Feature\Mcp;

use App\Enums\SequenceStatus;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Tools\CreateSegmentTool;
use App\Mcp\Tools\ListSegmentsTool;
use App\Mcp\Tools\SetSegmentArchivedTool;
use App\Mcp\Tools\UpdateSegmentTool;
use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class SegmentToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array<string, mixed>>
     */
    private function tagFilters(string $tag): array
    {
        return [['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => [$tag]]];
    }

    /**
     * Make a tag with one subscribed and one unsubscribed contact, and one
     * contact without the tag.
     */
    private function taggedContacts(User $user, string $name): Tag
    {
        $tag = Tag::factory()->for($user)->create(['name' => $name]);
        Contact::factory()->for($user)->create()->tags()->attach($tag);
        Contact::factory()->for($user)->unsubscribed()->create()->tags()->attach($tag);
        Contact::factory()->for($user)->create();

        return $tag;
    }

    public function test_segment_tools_use_the_documented_names(): void
    {
        $this->assertSame('list-segments', app(ListSegmentsTool::class)->name());
        $this->assertSame('create-segment', app(CreateSegmentTool::class)->name());
        $this->assertSame('update-segment', app(UpdateSegmentTool::class)->name());
        $this->assertSame('set-segment-archived', app(SetSegmentArchivedTool::class)->name());
    }

    public function test_list_segments_returns_the_active_segments_of_the_account_with_counts(): void
    {
        $user = User::factory()->create();
        $this->taggedContacts($user, 'vip');
        $segment = Segment::factory()->withFilters($this->tagFilters('vip'))->create(['client_id' => $user->client_id, 'name' => 'VIPs']);
        Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Old', 'archived_at' => now()]);
        Segment::factory()->create(['name' => 'Foreign']);
        Newsletter::factory()->for($user)->create(['status' => 'draft', 'filters' => [], 'segment_id' => $segment->id]);

        ByoMailerServer::actingAs($user)->tool(ListSegmentsTool::class, [])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('count', 1)
                ->where('segments.0.id', $segment->id)
                ->where('segments.0.name', 'VIPs')
                ->where('segments.0.filters', $this->tagFilters('vip'))
                ->where('segments.0.archived', false)
                ->where('segments.0.contacts_count', 2)
                ->where('segments.0.newsletters_count', 1)
                ->where('segments.0.sequences_count', 0)
                ->etc());
    }

    public function test_list_segments_lists_the_archived_segments_on_request(): void
    {
        $user = User::factory()->create();
        Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Active']);
        $archived = Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Old', 'archived_at' => now()]);

        ByoMailerServer::actingAs($user)->tool(ListSegmentsTool::class, ['status' => 'archived'])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('count', 1)
                ->where('segments.0.id', $archived->id)
                ->where('segments.0.archived', true)
                ->etc());

        ByoMailerServer::actingAs($user)->tool(ListSegmentsTool::class, ['status' => 'all'])
            ->assertOk()
            ->assertSee('"count":2');
    }

    public function test_create_segment_saves_the_segment_in_the_account(): void
    {
        $user = User::factory()->create();
        $this->taggedContacts($user, 'vip');

        ByoMailerServer::actingAs($user)->tool(CreateSegmentTool::class, [
            'name' => 'VIPs',
            'description' => 'Contacts with the vip tag',
            'filters' => $this->tagFilters('vip'),
        ])->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('name', 'VIPs')
                ->where('contacts_count', 2)
                ->where('archived', false)
                ->etc());

        $segment = Segment::query()->sole();
        $this->assertSame($user->client_id, $segment->client_id);
        $this->assertSame('Contacts with the vip tag', $segment->description);
        $this->assertSameFilters($this->tagFilters('vip'), $segment->filters);
    }

    public function test_create_segment_rejects_an_unknown_tag_and_saves_nothing(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSegmentTool::class, [
            'name' => 'Ghosts',
            'filters' => $this->tagFilters('ghost'),
        ])->assertHasErrors(['The tag "ghost" does not exist.']);

        $this->assertSame(0, Segment::query()->count());
    }

    public function test_create_segment_needs_at_least_one_filter(): void
    {
        $user = User::factory()->create();

        ByoMailerServer::actingAs($user)->tool(CreateSegmentTool::class, ['name' => 'Empty', 'filters' => []])
            ->assertHasErrors(['A segment needs at least one filter.']);

        $this->assertSame(0, Segment::query()->count());
    }

    public function test_create_segment_name_is_unique_in_the_account_only(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'VIPs']);
        Segment::factory()->create(['name' => 'Customers']);

        ByoMailerServer::actingAs($user)->tool(CreateSegmentTool::class, ['name' => 'VIPs', 'filters' => $this->tagFilters('vip')])
            ->assertHasErrors(['You already have a segment with this name.']);

        ByoMailerServer::actingAs($user)->tool(CreateSegmentTool::class, ['name' => 'Customers', 'filters' => $this->tagFilters('vip')])
            ->assertOk();

        $this->assertSame(1, Segment::query()->where('client_id', $user->client_id)->where('name', 'Customers')->count());
    }

    public function test_update_segment_changes_only_the_given_fields_of_a_segment_that_no_live_record_uses(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        $segment = Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Recent', 'description' => 'Keep me']);
        Newsletter::factory()->for($user)->create(['status' => 'draft', 'filters' => [], 'segment_id' => $segment->id]);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, [
            'segment_id' => $segment->id,
            'filters' => $this->tagFilters('vip'),
        ])->assertOk()->assertSee('"applied":true');

        $segment->refresh();
        $this->assertSame('Recent', $segment->name);
        $this->assertSame('Keep me', $segment->description);
        $this->assertSameFilters($this->tagFilters('vip'), $segment->filters);
    }

    public function test_update_segment_previews_a_filter_change_for_a_scheduled_newsletter_and_saves_after_confirmation(): void
    {
        $user = User::factory()->create();
        $this->taggedContacts($user, 'vip');
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $oldFilters = $segment->filters;
        $newsletter = Newsletter::factory()->for($user)->scheduled()->create(['filters' => [], 'segment_id' => $segment->id]);
        $arguments = ['segment_id' => $segment->id, 'name' => 'VIPs', 'filters' => $this->tagFilters('vip')];

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, $arguments)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('applied', false)
                ->where('new_filters', $this->tagFilters('vip'))
                ->where('new_contacts_count', 2)
                ->where('live_newsletters.0.id', $newsletter->id)
                ->where('live_newsletters.0.status', 'scheduled')
                ->where('live_sequences', [])
                ->etc());

        $segment->refresh();
        $this->assertSameFilters($oldFilters, $segment->filters);
        $this->assertNotSame('VIPs', $segment->name);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, [...$arguments, 'confirm_live' => true])
            ->assertOk()
            ->assertSee('"applied":true');

        $segment->refresh();
        $this->assertSameFilters($this->tagFilters('vip'), $segment->filters);
        $this->assertSame('VIPs', $segment->name);
        $this->assertSameFilters($this->tagFilters('vip'), $newsletter->refresh()->effectiveFilters());
    }

    public function test_update_segment_previews_a_filter_change_for_a_live_sequence(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $oldFilters = $segment->filters;
        $sequence = Sequence::factory()->for($user)->create(['status' => SequenceStatus::Live, 'filters' => [], 'segment_id' => $segment->id]);
        Sequence::factory()->for($user)->create(['status' => SequenceStatus::Draft, 'filters' => [], 'segment_id' => $segment->id]);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, [
            'segment_id' => $segment->id,
            'filters' => $this->tagFilters('vip'),
        ])->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('applied', false)
                ->where('live_newsletters', [])
                ->has('live_sequences', 1)
                ->where('live_sequences.0.id', $sequence->id)
                ->etc());

        $this->assertSameFilters($oldFilters, $segment->refresh()->filters);
    }

    public function test_update_segment_needs_no_confirmation_when_the_audience_of_a_live_record_does_not_change(): void
    {
        $user = User::factory()->create();
        Tag::factory()->for($user)->create(['name' => 'vip']);

        ByoMailerServer::actingAs($user)->tool(CreateSegmentTool::class, ['name' => 'VIPs', 'filters' => $this->tagFilters('vip')])->assertOk();
        $segment = Segment::query()->sole();
        Sequence::factory()->for($user)->create(['status' => SequenceStatus::Live, 'filters' => [], 'segment_id' => $segment->id]);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, [
            'segment_id' => $segment->id,
            'name' => 'Best customers',
            'description' => 'Renamed',
            'filters' => $this->tagFilters('vip'),
        ])->assertOk()->assertSee('"applied":true');

        $segment->refresh();
        $this->assertSame('Best customers', $segment->name);
        $this->assertSame('Renamed', $segment->description);
    }

    public function test_update_segment_rejects_a_bad_filter_and_keeps_the_old_filters(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $oldFilters = $segment->filters;

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, [
            'segment_id' => $segment->id,
            'filters' => $this->tagFilters('ghost'),
        ])->assertHasErrors(['The tag "ghost" does not exist.']);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, [
            'segment_id' => $segment->id,
            'filters' => [],
        ])->assertHasErrors(['A segment needs at least one filter.']);

        $this->assertSameFilters($oldFilters, $segment->refresh()->filters);
    }

    public function test_update_segment_rejects_the_name_of_another_segment_but_keeps_its_own_name(): void
    {
        $user = User::factory()->create();
        Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Taken']);
        $segment = Segment::factory()->create(['client_id' => $user->client_id, 'name' => 'Mine']);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, ['segment_id' => $segment->id, 'name' => 'Taken'])
            ->assertHasErrors(['You already have a segment with this name.']);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, ['segment_id' => $segment->id, 'name' => 'Mine'])
            ->assertOk();

        $this->assertSame('Mine', $segment->refresh()->name);
    }

    public function test_segment_write_tools_return_an_error_for_a_segment_of_another_account(): void
    {
        $user = User::factory()->create();
        $foreign = Segment::factory()->create(['name' => 'Foreign']);

        ByoMailerServer::actingAs($user)->tool(UpdateSegmentTool::class, ['segment_id' => $foreign->id, 'name' => 'Stolen'])
            ->assertHasErrors(["No segment found with id {$foreign->id}."]);

        ByoMailerServer::actingAs($user)->tool(SetSegmentArchivedTool::class, ['segment_id' => $foreign->id, 'archived' => true])
            ->assertHasErrors(["No segment found with id {$foreign->id}."]);

        $foreign->refresh();
        $this->assertSame('Foreign', $foreign->name);
        $this->assertNull($foreign->archived_at);
    }

    public function test_set_segment_archived_archives_and_restores_a_segment_and_keeps_its_users(): void
    {
        $user = User::factory()->create();
        $segment = Segment::factory()->create(['client_id' => $user->client_id]);
        $sequence = Sequence::factory()->for($user)->create(['status' => SequenceStatus::Live, 'filters' => [], 'segment_id' => $segment->id]);

        ByoMailerServer::actingAs($user)->tool(SetSegmentArchivedTool::class, ['segment_id' => $segment->id, 'archived' => true])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('archived', true)
                ->where('applied', true)
                ->etc());

        $this->assertNotNull($segment->refresh()->archived_at);
        $this->assertSame($segment->id, $sequence->refresh()->segment_id);

        ByoMailerServer::actingAs($user)->tool(SetSegmentArchivedTool::class, ['segment_id' => $segment->id, 'archived' => true])
            ->assertOk()
            ->assertSee('"applied":false');

        ByoMailerServer::actingAs($user)->tool(SetSegmentArchivedTool::class, ['segment_id' => $segment->id, 'archived' => false])
            ->assertOk()
            ->assertSee('"applied":true');

        $this->assertNull($segment->refresh()->archived_at);
    }

    /**
     * Compare two filter arrays without key order. MySQL returns the keys of a
     * JSON column in its own order.
     *
     * @param  array<array-key, mixed>  $expected
     * @param  array<array-key, mixed>|null  $actual
     */
    private function assertSameFilters(array $expected, ?array $actual): void
    {
        $sort = function (array $value) use (&$sort): array {
            ksort($value);

            return array_map(fn (mixed $item): mixed => is_array($item) ? $sort($item) : $item, $value);
        };

        $this->assertSame($sort($expected), $sort($actual ?? []));
    }
}
