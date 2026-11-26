<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\User;
use App\Services\ContactFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SegmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_has_segments_and_segment_id_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('segments', ['id', 'client_id', 'name', 'description', 'filters']));
        $this->assertTrue(Schema::hasColumn('newsletters', 'segment_id'));
        $this->assertTrue(Schema::hasColumn('sequences', 'segment_id'));
    }

    public function test_segment_is_scoped_to_its_client(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $segmentA = Segment::factory()->create(['client_id' => $userA->client_id]);
        Segment::factory()->create(['client_id' => $userB->client_id]);

        $this->actingAs($userA);

        $this->assertEquals([$segmentA->id], Segment::query()->pluck('id')->all());
    }

    private function attributeFilters(string $value): array
    {
        return [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'plan', 'operator' => 'equals', 'value' => $value]],
        ]];
    }

    public function test_effective_filters_returns_inline_when_no_segment(): void
    {
        $newsletter = Newsletter::factory()->create(['filters' => $this->attributeFilters('pro')]);

        $this->assertSame($this->attributeFilters('pro'), $newsletter->effectiveFilters());
    }

    public function test_effective_filters_prefers_segment_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $segment = Segment::factory()->create([
            'client_id' => $user->client_id,
            'filters' => $this->attributeFilters('enterprise'),
        ]);
        $newsletter = Newsletter::factory()->create([
            'user_id' => $user->id,
            'segment_id' => $segment->id,
            'filters' => $this->attributeFilters('pro'),
        ]);

        $this->assertSameJsonData($this->attributeFilters('enterprise'), $newsletter->fresh()->effectiveFilters());
    }

    public function test_effective_filters_rejects_cross_client_segment(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = Segment::factory()->create(['client_id' => $other->client_id]);

        $sequence = Sequence::factory()->create(['user_id' => $owner->id]);
        $sequence->segment_id = $foreign->id;
        $sequence->setRelation('segment', $foreign);

        $this->assertSame([], $sequence->effectiveFilters());
    }

    public function test_effective_filters_returns_empty_when_segment_missing(): void
    {
        $sequence = Sequence::factory()->create();
        $this->actingAsTenant($sequence->client_id);
        $sequence->segment_id = 999999;

        $this->assertSame([], $sequence->effectiveFilters());
    }

    public function test_segment_contacts_query_includes_unsubscribed_and_excludes_non_matches(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $match = Contact::factory()->create(['user_id' => $user->id, 'extra_attributes' => ['plan' => 'pro']]);
        $unsubMatch = Contact::factory()->unsubscribed()->create(['user_id' => $user->id, 'extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->create(['user_id' => $user->id, 'extra_attributes' => ['plan' => 'free']]);

        $otherUser = User::factory()->create();
        Contact::factory()->create(['user_id' => $otherUser->id, 'extra_attributes' => ['plan' => 'pro']]);

        $ids = app(ContactFilterService::class)
            ->segmentContactsQuery($user->client_id, $this->attributeFilters('pro'))
            ->pluck('id')
            ->all();

        sort($ids);
        $expected = [$match->id, $unsubMatch->id];
        sort($expected);

        $this->assertSame($expected, $ids);
    }
}
