<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Services\ContactFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The send path reads the stored filters without validation. A stored
 * filter can come from an old version of the app, so it can miss a key or
 * hold a value that the app does not know. An unknown value must never make
 * the audience larger.
 */
class StoredFilterValuesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAsTenant($this->user->client_id);
    }

    public function test_an_attribute_filter_without_a_mode_matches_all_values(): void
    {
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'pro', 'city' => 'Paris']]);
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'pro']]);

        $count = $this->recipientCount([[
            'category' => 'attributes',
            'values' => [
                ['key' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
                ['key' => 'city', 'operator' => 'equals', 'value' => 'Paris'],
            ],
        ]]);

        $this->assertSame(1, $count);
    }

    public function test_a_tag_filter_without_a_mode_matches_any_value(): void
    {
        $vip = Tag::factory()->for($this->user)->create(['name' => 'vip']);
        $gold = Tag::factory()->for($this->user)->create(['name' => 'gold']);
        Contact::factory()->for($this->user)->create()->tags()->attach($vip);
        Contact::factory()->for($this->user)->create()->tags()->attach($gold);
        Contact::factory()->for($this->user)->create();

        $this->assertSame(2, $this->recipientCount([['category' => 'tags', 'values' => ['vip', 'gold']]]));
    }

    public function test_a_list_filter_without_a_mode_matches_any_value(): void
    {
        $first = MailList::factory()->for($this->user)->create();
        $second = MailList::factory()->for($this->user)->create();
        Contact::factory()->for($this->user)->create()->lists()->attach($first);
        Contact::factory()->for($this->user)->create()->lists()->attach($second);
        Contact::factory()->for($this->user)->create();

        $this->assertSame(2, $this->recipientCount([['category' => 'lists', 'values' => [$first->id, $second->id]]]));
    }

    public function test_a_topic_filter_without_a_mode_matches_any_value(): void
    {
        $first = Topic::factory()->for($this->user)->create();
        $second = Topic::factory()->for($this->user)->create();
        Contact::factory()->for($this->user)->create()->topics()->attach($first, ['subscribed_at' => now()]);
        Contact::factory()->for($this->user)->create()->topics()->attach($second, ['subscribed_at' => now()]);
        Contact::factory()->for($this->user)->create();

        $this->assertSame(2, $this->recipientCount([['category' => 'topics', 'values' => [$first->id, $second->id]]]));
    }

    public function test_a_location_filter_without_a_mode_matches_any_value(): void
    {
        Contact::factory()->for($this->user)->inCountry('US')->create();
        Contact::factory()->for($this->user)->inCountry('GB')->create();
        Contact::factory()->for($this->user)->inCountry('MX')->create();

        $this->assertSame(2, $this->recipientCount([['category' => 'location', 'field' => 'country', 'values' => ['US', 'GB']]]));
    }

    public function test_a_filter_without_a_category_is_an_attribute_filter(): void
    {
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'free']]);

        $count = $this->recipientCount([[
            'values' => [['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
        ]]);

        $this->assertSame(1, $count);
    }

    public function test_a_filter_with_an_unknown_category_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->count(2)->create();

        $this->assertSame(0, $this->recipientCount([['connector' => 'and', 'category' => 'segments', 'mode' => 'any', 'values' => ['vip']]]));
        $this->assertSame(0, $this->recipientCount([['connector' => 'and', 'category' => 7, 'mode' => 'any', 'values' => ['vip']]]));
    }

    public function test_a_filter_with_an_unknown_mode_matches_no_contact(): void
    {
        $vip = Tag::factory()->for($this->user)->create(['name' => 'vip']);
        Contact::factory()->for($this->user)->create()->tags()->attach($vip);
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($this->user)->inCountry('US')->create();

        $this->assertSame(0, $this->recipientCount([['category' => 'tags', 'mode' => 'not_any', 'values' => ['vip']]]));
        $this->assertSame(0, $this->recipientCount([[
            'category' => 'attributes',
            'mode' => 'not_all',
            'values' => [['key' => 'plan', 'operator' => 'equals', 'value' => 'pro']],
        ]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'location', 'field' => 'country', 'mode' => ['none'], 'values' => ['MX']]]));
    }

    public function test_an_unknown_connector_joins_the_filters_with_and(): void
    {
        $vip = Tag::factory()->for($this->user)->create(['name' => 'vip']);
        $gold = Tag::factory()->for($this->user)->create(['name' => 'gold']);
        Contact::factory()->for($this->user)->create()->tags()->attach([$vip->id, $gold->id]);
        Contact::factory()->for($this->user)->create()->tags()->attach($vip);
        Contact::factory()->for($this->user)->create()->tags()->attach($gold);

        $count = $this->recipientCount([
            ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
            ['connector' => 'xor', 'category' => 'tags', 'mode' => 'any', 'values' => ['gold']],
        ]);

        $this->assertSame(1, $count);
    }

    public function test_a_date_filter_with_an_unknown_operator_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->count(2)->create(['created_at' => now()->subDays(10)]);

        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => 'on', 'values' => ['date' => '2020-01-01']]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => ['before'], 'values' => ['date' => '2099-01-01']]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'values' => ['days' => 30]]]));
    }

    public function test_a_date_filter_without_a_valid_value_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->count(2)->create(['created_at' => now()->subDays(10)]);

        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => 'before', 'values' => []]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => 'after']]));
        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => 'between', 'values' => ['from' => '2020-01-01']]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => 'more_than', 'values' => []]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => 'more_than', 'values' => ['days' => 'many']]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'date', 'operator' => 'more_than', 'values' => ['days' => 0]]]));
    }

    public function test_a_valid_date_filter_keeps_its_audience(): void
    {
        Contact::factory()->for($this->user)->create(['created_at' => now()->subDays(10)]);
        Contact::factory()->for($this->user)->create(['created_at' => now()->subDays(2)]);

        $this->assertSame(1, $this->recipientCount([['category' => 'date', 'operator' => 'more_than', 'values' => ['days' => 5]]]));
        $this->assertSame(1, $this->recipientCount([['category' => 'date', 'operator' => 'in_last', 'values' => ['days' => '5']]]));
    }

    public function test_an_engagement_filter_with_an_unknown_operator_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->count(2)->create();

        $this->assertSame(0, $this->recipientCount([$this->engagementFilter('bounced')]));
        $this->assertSame(0, $this->recipientCount([$this->engagementFilter('not_bounced')]));
        $this->assertSame(0, $this->recipientCount([$this->engagementFilter(['not_opened'])]));
        $this->assertSame(0, $this->recipientCount([['category' => 'engagement', 'values' => ['within' => 'ever']]]));
    }

    public function test_an_engagement_filter_with_an_unknown_window_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->count(2)->create();

        $this->assertSame(0, $this->recipientCount([$this->engagementFilter('not_opened', ['within' => 'last_week'])]));
        $this->assertSame(0, $this->recipientCount([$this->engagementFilter('not_opened', ['within' => 'last_n_days', 'days' => 0])]));
        $this->assertSame(0, $this->recipientCount([$this->engagementFilter('not_opened', ['within' => 'last_n_days', 'days' => 'many'])]));
    }

    public function test_an_engagement_filter_with_an_unknown_newsletter_matches_no_contact(): void
    {
        $newsletter = Newsletter::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened()->create(['contact_id' => $contact->id]);

        $this->assertSame(1, $this->recipientCount([$this->engagementFilter('opened', ['newsletter_id' => null])]));
        $this->assertSame(0, $this->recipientCount([$this->engagementFilter('opened', ['newsletter_id' => 'latest'])]));
    }

    public function test_an_engagement_filter_without_a_window_matches_ever(): void
    {
        $newsletter = Newsletter::factory()->for($this->user)->create();
        $contact = Contact::factory()->for($this->user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened(Carbon::now()->subYears(2))->create(['contact_id' => $contact->id]);
        Contact::factory()->for($this->user)->create();

        $this->assertSame(1, $this->recipientCount([['category' => 'engagement', 'operator' => 'opened']]));
        $this->assertSame(1, $this->recipientCount([['category' => 'engagement', 'operator' => 'not_opened', 'values' => []]]));
    }

    public function test_an_attribute_filter_with_an_unknown_operator_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'free']]);

        foreach (['all', 'any', 'none'] as $mode) {
            $this->assertSame(0, $this->recipientCount([[
                'category' => 'attributes',
                'mode' => $mode,
                'values' => [['key' => 'plan', 'operator' => 'starts_with', 'value' => 'pro']],
            ]]), "mode {$mode}");
        }

        $this->assertSame(0, $this->recipientCount([[
            'category' => 'attributes',
            'mode' => 'any',
            'values' => [
                ['key' => 'plan', 'operator' => 'equals', 'value' => 'free'],
                ['key' => 'plan', 'operator' => ['equals'], 'value' => 'pro'],
            ],
        ]]));
    }

    public function test_an_attribute_value_without_an_operator_uses_equals(): void
    {
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'pro']]);
        Contact::factory()->for($this->user)->create(['extra_attributes' => ['plan' => 'free']]);

        $this->assertSame(1, $this->recipientCount([['category' => 'attributes', 'mode' => 'all', 'values' => [['key' => 'plan', 'value' => 'pro']]]]));
        $this->assertSame(1, $this->recipientCount([['category' => 'attributes', 'mode' => 'none', 'values' => [['key' => 'plan', 'value' => 'pro']]]]));
    }

    public function test_a_location_filter_with_an_unknown_field_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->inCountry('US')->create();
        Contact::factory()->for($this->user)->inCountry('GB')->create();

        $this->assertSame(0, $this->recipientCount([['category' => 'location', 'field' => 'city', 'mode' => 'any', 'values' => ['Paris']]]));
        $this->assertSame(0, $this->recipientCount([['category' => 'location', 'field' => 'city', 'mode' => 'none', 'values' => ['Paris']]]));
    }

    public function test_a_location_filter_without_a_field_uses_the_country(): void
    {
        Contact::factory()->for($this->user)->inCountry('US')->create();
        Contact::factory()->for($this->user)->inCountry('GB')->create();

        $this->assertSame(1, $this->recipientCount([['category' => 'location', 'mode' => 'any', 'values' => ['US']]]));
    }

    public function test_a_location_filter_with_the_all_mode_matches_no_contact(): void
    {
        Contact::factory()->for($this->user)->inCountry('US')->create();
        Contact::factory()->for($this->user)->inCountry('GB')->create();

        $this->assertSame(0, $this->recipientCount([['category' => 'location', 'field' => 'country', 'mode' => 'all', 'values' => ['US', 'GB']]]));
    }

    public function test_the_single_contact_check_agrees_with_the_query(): void
    {
        $contact = Contact::factory()->for($this->user)->inCountry('US')->create(['extra_attributes' => ['plan' => 'pro']]);
        $service = app(ContactFilterService::class);

        $this->assertFalse($service->contactMatchesFilters($contact, [$this->engagementFilter('not_bounced')]));
        $this->assertFalse($service->contactMatchesFilters($contact, [['category' => 'date', 'operator' => 'on', 'values' => ['date' => '2099-01-01']]]));
        $this->assertFalse($service->contactMatchesFilters($contact, [[
            'category' => 'attributes',
            'mode' => 'none',
            'values' => [['key' => 'plan', 'operator' => 'starts_with', 'value' => 'free']],
        ]]));
        $this->assertFalse($service->contactMatchesFilters($contact, [['category' => 'location', 'field' => 'city', 'mode' => 'none', 'values' => ['Paris']]]));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function engagementFilter(mixed $operator, array $values = []): array
    {
        return [
            'category' => 'engagement',
            'operator' => $operator,
            'values' => [...['newsletter_id' => null, 'within' => 'ever', 'days' => null], ...$values],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filters
     */
    private function recipientCount(array $filters): int
    {
        return app(ContactFilterService::class)->recipientCount($this->user->client_id, $filters);
    }
}
