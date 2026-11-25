<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContactFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    private ContactFilterService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ContactFilterService::class);
    }

    public function test_newsletter_recipients_skip_sunset_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $active = Contact::factory()->for($user)->create();
        Contact::factory()->for($user)->create(['sunset_at' => now()]);

        $ids = $this->service->newsletterRecipientsQuery($user->client_id, [])->pluck('id')->all();

        $this->assertSame([$active->id], $ids);
    }

    public function test_segment_still_includes_sunset_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        Contact::factory()->for($user)->create();
        Contact::factory()->for($user)->create(['sunset_at' => now()]);

        $this->assertSame(2, $this->service->segmentContactsQuery($user->client_id, [])->count());
    }

    public function test_empty_filters_match_all_contacts(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $contact = Contact::factory()->for($user)->create();

        $query = Contact::query()->where('id', $contact->id);
        $result = $this->service->applyFiltersToQuery($query, []);

        $this->assertSame(1, $result->count());
    }

    public function test_contact_matches_filters_returns_true_with_empty_filters(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->for($user)->create();

        $this->assertTrue($this->service->contactMatchesFilters($contact, []));
    }

    public function test_list_filter_with_any_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $listA = MailList::factory()->for($user)->create();
        $listB = MailList::factory()->for($user)->create();

        $matching = Contact::factory()->for($user)->create();
        $matching->lists()->attach($listA);

        $nonMatching = Contact::factory()->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$listA->id, $listB->id],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($matching, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($nonMatching, $filters));
    }

    public function test_list_filter_with_all_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $listA = MailList::factory()->for($user)->create();
        $listB = MailList::factory()->for($user)->create();

        $hasAll = Contact::factory()->for($user)->create();
        $hasAll->lists()->attach([$listA->id, $listB->id]);

        $hasOne = Contact::factory()->for($user)->create();
        $hasOne->lists()->attach($listA);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'all',
                'values' => [$listA->id, $listB->id],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($hasAll, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($hasOne, $filters));
    }

    public function test_list_filter_with_none_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();

        $onList = Contact::factory()->for($user)->create();
        $onList->lists()->attach($list);

        $notOnList = Contact::factory()->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'none',
                'values' => [$list->id],
            ],
        ];

        $this->assertFalse($this->service->contactMatchesFilters($onList, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($notOnList, $filters));
    }

    public function test_tag_filter_with_any_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);

        $tagged = Contact::factory()->for($user)->create();
        $tagged->tags()->attach($tag);

        $untagged = Contact::factory()->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip'],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($tagged, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($untagged, $filters));
    }

    public function test_tag_filter_with_all_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tagA = Tag::factory()->for($user)->create(['name' => 'vip']);
        $tagB = Tag::factory()->for($user)->create(['name' => 'premium']);

        $hasBoth = Contact::factory()->for($user)->create();
        $hasBoth->tags()->attach([$tagA->id, $tagB->id]);

        $hasOne = Contact::factory()->for($user)->create();
        $hasOne->tags()->attach($tagA);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'all',
                'values' => ['vip', 'premium'],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($hasBoth, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($hasOne, $filters));
    }

    public function test_tag_filter_with_none_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'unsubscribed']);

        $tagged = Contact::factory()->for($user)->create();
        $tagged->tags()->attach($tag);

        $untagged = Contact::factory()->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'none',
                'values' => ['unsubscribed'],
            ],
        ];

        $this->assertFalse($this->service->contactMatchesFilters($tagged, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($untagged, $filters));
    }

    public function test_attribute_filter_with_all_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $matching = Contact::factory()->for($user)->create([
            'extra_attributes' => ['plan' => 'pro', 'status' => 'active'],
        ]);

        $partial = Contact::factory()->for($user)->create([
            'extra_attributes' => ['plan' => 'pro', 'status' => 'inactive'],
        ]);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'all',
                'values' => [
                    ['key' => 'plan', 'value' => 'pro'],
                    ['key' => 'status', 'value' => 'active'],
                ],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($matching, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($partial, $filters));
    }

    public function test_attribute_filter_with_any_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $matching = Contact::factory()->for($user)->create([
            'extra_attributes' => ['plan' => 'free'],
        ]);

        $nonMatching = Contact::factory()->for($user)->create([
            'extra_attributes' => ['plan' => 'enterprise'],
        ]);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'any',
                'values' => [
                    ['key' => 'plan', 'value' => 'free'],
                    ['key' => 'plan', 'value' => 'pro'],
                ],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($matching, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($nonMatching, $filters));
    }

    public function test_attribute_filter_with_none_mode(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $matching = Contact::factory()->for($user)->create([
            'extra_attributes' => ['plan' => 'enterprise'],
        ]);

        $nonMatching = Contact::factory()->for($user)->create([
            'extra_attributes' => ['plan' => 'free'],
        ]);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'attributes',
                'mode' => 'none',
                'values' => [
                    ['key' => 'plan', 'value' => 'free'],
                ],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($matching, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($nonMatching, $filters));
    }

    public function test_multiple_filters_with_and_connector(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);

        $matchesBoth = Contact::factory()->for($user)->create();
        $matchesBoth->lists()->attach($list);
        $matchesBoth->tags()->attach($tag);

        $matchesOnlyList = Contact::factory()->for($user)->create();
        $matchesOnlyList->lists()->attach($list);

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'and',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip'],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($matchesBoth, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($matchesOnlyList, $filters));
    }

    public function test_multiple_filters_with_or_connector(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $list = MailList::factory()->for($user)->create();
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);

        $matchesList = Contact::factory()->for($user)->create();
        $matchesList->lists()->attach($list);

        $matchesTag = Contact::factory()->for($user)->create();
        $matchesTag->tags()->attach($tag);

        $matchesNeither = Contact::factory()->for($user)->create();

        $filters = [
            [
                'connector' => 'and',
                'category' => 'lists',
                'mode' => 'any',
                'values' => [$list->id],
            ],
            [
                'connector' => 'or',
                'category' => 'tags',
                'mode' => 'any',
                'values' => ['vip'],
            ],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($matchesList, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($matchesTag, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($matchesNeither, $filters));
    }

    public function test_attribute_contains_operator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $match = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'pro-monthly']]);
        $noMatch = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'plan', 'operator' => 'contains', 'value' => 'pro']],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($match, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($noMatch, $filters));
    }

    public function test_attribute_not_equals_operator_matches_missing_key(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $other = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);
        $missing = Contact::factory()->for($user)->create(['extra_attributes' => []]);
        $equal = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'pro']]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'plan', 'operator' => 'not_equals', 'value' => 'pro']],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($other, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($missing, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($equal, $filters));
    }

    public function test_attribute_numeric_greater_than_operator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $high = Contact::factory()->for($user)->create(['extra_attributes' => ['age' => '40']]);
        $low = Contact::factory()->for($user)->create(['extra_attributes' => ['age' => '20']]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'age', 'operator' => 'gt', 'value' => '30']],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($high, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($low, $filters));
    }

    public function test_attribute_is_set_operator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $set = Contact::factory()->for($user)->create(['extra_attributes' => ['vip' => 'yes']]);
        $unset = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'vip', 'operator' => 'is_set', 'value' => '']],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($set, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($unset, $filters));
    }

    public function test_date_before_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $old = Contact::factory()->for($user)->createdOn('2026-01-01')->create();
        $new = Contact::factory()->for($user)->createdOn('2026-05-01')->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'date',
            'operator' => 'before',
            'values' => ['date' => '2026-03-01'],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($old, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($new, $filters));
    }

    public function test_date_in_last_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $recent = Contact::factory()->for($user)->createdDaysAgo(3)->create();
        $stale = Contact::factory()->for($user)->createdDaysAgo(60)->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'date',
            'operator' => 'in_last',
            'values' => ['days' => 7],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($recent, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($stale, $filters));
    }

    public function test_date_between_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $inside = Contact::factory()->for($user)->createdOn('2026-02-15')->create();
        $outside = Contact::factory()->for($user)->createdOn('2026-04-15')->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'date',
            'operator' => 'between',
            'values' => ['from' => '2026-02-01', 'to' => '2026-03-01'],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($inside, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($outside, $filters));
    }

    public function test_engagement_opened_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $opened = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened()
            ->create(['contact_id' => $opened->id]);

        $notOpened = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->sent()
            ->create(['contact_id' => $notOpened->id]);

        $filters = [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => 'opened',
            'values' => ['newsletter_id' => null, 'within' => 'ever', 'days' => null],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($opened, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($notOpened, $filters));
    }

    public function test_engagement_not_received_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $received = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->delivered()
            ->create(['contact_id' => $received->id]);

        $never = Contact::factory()->for($user)->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => 'not_received',
            'values' => ['newsletter_id' => null, 'within' => 'ever', 'days' => null],
        ]];

        $this->assertFalse($this->service->contactMatchesFilters($received, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($never, $filters));
    }

    public function test_engagement_within_last_n_days_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $recent = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened(Carbon::now()->subDays(2))
            ->create(['contact_id' => $recent->id]);

        $stale = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened(Carbon::now()->subDays(40))
            ->create(['contact_id' => $stale->id]);

        $filters = [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => 'opened',
            'values' => ['newsletter_id' => null, 'within' => 'last_n_days', 'days' => 7],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($recent, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($stale, $filters));
    }

    public function test_location_country_any_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $us = Contact::factory()->for($user)->inCountry('US')->create();
        $gb = Contact::factory()->for($user)->inCountry('GB')->create();
        $fr = Contact::factory()->for($user)->inCountry('FR')->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'location',
            'field' => 'country',
            'mode' => 'any',
            'values' => ['US', 'GB'],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($us, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($gb, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($fr, $filters));
    }

    public function test_location_country_none_filter_includes_null_country(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $us = Contact::factory()->for($user)->inCountry('US')->create();
        $unknown = Contact::factory()->for($user)->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'location',
            'field' => 'country',
            'mode' => 'none',
            'values' => ['US'],
        ]];

        $this->assertFalse($this->service->contactMatchesFilters($us, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($unknown, $filters));
    }

    public function test_location_timezone_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $ny = Contact::factory()->for($user)->inTimezone('America/New_York')->create();
        $london = Contact::factory()->for($user)->inTimezone('Europe/London')->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'location',
            'field' => 'timezone',
            'mode' => 'any',
            'values' => ['America/New_York'],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($ny, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($london, $filters));
    }

    public function test_date_after_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $old = Contact::factory()->for($user)->createdOn('2026-01-01')->create();
        $new = Contact::factory()->for($user)->createdOn('2026-05-01')->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'date',
            'operator' => 'after',
            'values' => ['date' => '2026-03-01'],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($new, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($old, $filters));
    }

    public function test_date_more_than_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $stale = Contact::factory()->for($user)->createdDaysAgo(60)->create();
        $recent = Contact::factory()->for($user)->createdDaysAgo(3)->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'date',
            'operator' => 'more_than',
            'values' => ['days' => 30],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($stale, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($recent, $filters));
    }

    public function test_date_between_includes_full_end_day(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $endOfDay = Contact::factory()->for($user)->createdOn('2026-03-01 23:59:59')->create();
        $nextDay = Contact::factory()->for($user)->createdOn('2026-03-02 00:00:00')->create();

        $filters = [[
            'connector' => 'and',
            'category' => 'date',
            'operator' => 'between',
            'values' => ['from' => '2026-02-01', 'to' => '2026-03-01'],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($endOfDay, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($nextDay, $filters));
    }

    public function test_engagement_filter_scoped_to_specific_newsletter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletterA = Newsletter::factory()->for($user)->create();
        $newsletterB = Newsletter::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletterA)->opened()
            ->create(['contact_id' => $contact->id]);

        $matchesA = [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => 'opened',
            'values' => ['newsletter_id' => $newsletterA->id, 'within' => 'ever', 'days' => null],
        ]];
        $matchesB = [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => 'opened',
            'values' => ['newsletter_id' => $newsletterB->id, 'within' => 'ever', 'days' => null],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($contact, $matchesA));
        $this->assertFalse($this->service->contactMatchesFilters($contact, $matchesB));
    }

    public function test_engagement_clicked_and_not_clicked_filters(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $clicked = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->clicked()
            ->create(['contact_id' => $clicked->id]);

        $notClicked = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened()
            ->create(['contact_id' => $notClicked->id]);

        $clickedFilter = [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => 'clicked',
            'values' => ['newsletter_id' => null, 'within' => 'ever', 'days' => null],
        ]];
        $notClickedFilter = [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => 'not_clicked',
            'values' => ['newsletter_id' => null, 'within' => 'ever', 'days' => null],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($clicked, $clickedFilter));
        $this->assertFalse($this->service->contactMatchesFilters($notClicked, $clickedFilter));
        $this->assertTrue($this->service->contactMatchesFilters($notClicked, $notClickedFilter));
        $this->assertFalse($this->service->contactMatchesFilters($clicked, $notClickedFilter));
    }

    public function test_attribute_lt_operator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $low = Contact::factory()->for($user)->create(['extra_attributes' => ['age' => '20']]);
        $high = Contact::factory()->for($user)->create(['extra_attributes' => ['age' => '40']]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'age', 'operator' => 'lt', 'value' => '30']],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($low, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($high, $filters));
    }

    public function test_attribute_not_contains_operator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $clean = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);
        $hit = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'pro-monthly']]);
        $missing = Contact::factory()->for($user)->create(['extra_attributes' => []]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'plan', 'operator' => 'not_contains', 'value' => 'pro']],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($clean, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($missing, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($hit, $filters));
    }

    public function test_attribute_is_not_set_operator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $unset = Contact::factory()->for($user)->create(['extra_attributes' => []]);
        $set = Contact::factory()->for($user)->create(['extra_attributes' => ['vip' => 'yes']]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'all',
            'values' => [['key' => 'vip', 'operator' => 'is_not_set', 'value' => '']],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($unset, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($set, $filters));
    }

    public function test_attribute_none_mode_with_contains_operator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $hit = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'pro-monthly']]);
        $clean = Contact::factory()->for($user)->create(['extra_attributes' => ['plan' => 'free']]);

        // none + contains 'pro' => contact must NOT contain 'pro'.
        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'none',
            'values' => [['key' => 'plan', 'operator' => 'contains', 'value' => 'pro']],
        ]];

        $this->assertFalse($this->service->contactMatchesFilters($hit, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($clean, $filters));
    }

    public function test_attribute_any_mode_with_mixed_operators(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $byAge = Contact::factory()->for($user)->create(['extra_attributes' => ['age' => '50', 'plan' => 'free']]);
        $byPlan = Contact::factory()->for($user)->create(['extra_attributes' => ['age' => '20', 'plan' => 'pro']]);
        $neither = Contact::factory()->for($user)->create(['extra_attributes' => ['age' => '20', 'plan' => 'free']]);

        $filters = [[
            'connector' => 'and',
            'category' => 'attributes',
            'mode' => 'any',
            'values' => [
                ['key' => 'age', 'operator' => 'gt', 'value' => '40'],
                ['key' => 'plan', 'operator' => 'equals', 'value' => 'pro'],
            ],
        ]];

        $this->assertTrue($this->service->contactMatchesFilters($byAge, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($byPlan, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($neither, $filters));
    }

    public function test_or_connector_with_date_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $tag = Tag::factory()->for($user)->create(['name' => 'vip']);

        $matchesTag = Contact::factory()->for($user)->createdDaysAgo(60)->create();
        $matchesTag->tags()->attach($tag);

        $matchesDate = Contact::factory()->for($user)->createdDaysAgo(2)->create();
        $matchesNeither = Contact::factory()->for($user)->createdDaysAgo(60)->create();

        $filters = [
            ['connector' => 'and', 'category' => 'tags', 'mode' => 'any', 'values' => ['vip']],
            ['connector' => 'or', 'category' => 'date', 'operator' => 'in_last', 'values' => ['days' => 7]],
        ];

        $this->assertTrue($this->service->contactMatchesFilters($matchesTag, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($matchesDate, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($matchesNeither, $filters));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function engagementFilter(string $operator, ?int $newsletterId = null, string $within = 'ever', ?int $days = null): array
    {
        return [[
            'connector' => 'and',
            'category' => 'engagement',
            'operator' => $operator,
            'values' => ['newsletter_id' => $newsletterId, 'within' => $within, 'days' => $days],
        ]];
    }

    public function test_engagement_received_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $received = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->delivered()
            ->create(['contact_id' => $received->id]);

        $never = Contact::factory()->for($user)->create();

        $filters = $this->engagementFilter('received');

        $this->assertTrue($this->service->contactMatchesFilters($received, $filters));
        $this->assertFalse($this->service->contactMatchesFilters($never, $filters));
    }

    public function test_engagement_not_opened_filter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $opened = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened()
            ->create(['contact_id' => $opened->id]);

        $sentNotOpened = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->sent()
            ->create(['contact_id' => $sentNotOpened->id]);

        $neverSent = Contact::factory()->for($user)->create();

        $filters = $this->engagementFilter('not_opened');

        // Did not open: a sent-but-unopened contact and a never-sent contact
        // both match; a contact who opened does not.
        $this->assertFalse($this->service->contactMatchesFilters($opened, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($sentNotOpened, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($neverSent, $filters));
    }

    public function test_engagement_not_opened_is_false_when_any_newsletter_opened(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletterA = Newsletter::factory()->for($user)->create();
        $newsletterB = Newsletter::factory()->for($user)->create();

        // Opened A, merely sent B — "did not open any" must still be false.
        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletterA)->opened()
            ->create(['contact_id' => $contact->id]);
        ContactMail::factory()->forNewsletter($newsletterB)->sent()
            ->create(['contact_id' => $contact->id]);

        $this->assertFalse(
            $this->service->contactMatchesFilters($contact, $this->engagementFilter('not_opened'))
        );
    }

    public function test_engagement_not_opened_for_specific_newsletter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletterA = Newsletter::factory()->for($user)->create();
        $newsletterB = Newsletter::factory()->for($user)->create();

        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletterA)->opened()
            ->create(['contact_id' => $contact->id]);

        // Opened A but not B.
        $this->assertFalse(
            $this->service->contactMatchesFilters($contact, $this->engagementFilter('not_opened', $newsletterA->id))
        );
        $this->assertTrue(
            $this->service->contactMatchesFilters($contact, $this->engagementFilter('not_opened', $newsletterB->id))
        );
    }

    public function test_engagement_not_opened_within_last_n_days(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $openedRecently = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened(Carbon::now()->subDays(2))
            ->create(['contact_id' => $openedRecently->id]);

        $openedLongAgo = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened(Carbon::now()->subDays(40))
            ->create(['contact_id' => $openedLongAgo->id]);

        $filters = $this->engagementFilter('not_opened', null, 'last_n_days', 7);

        // "Did not open in the last 7 days" — a 40-day-old open still counts as
        // not-opened-recently; a 2-day-old open does not.
        $this->assertFalse($this->service->contactMatchesFilters($openedRecently, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($openedLongAgo, $filters));
    }

    public function test_engagement_not_opened_within_window_respects_mixed_history(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletterA = Newsletter::factory()->for($user)->create();
        $newsletterB = Newsletter::factory()->for($user)->create();

        // One stale open, one recent open — a recent open anywhere disqualifies.
        $contact = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletterA)->opened(Carbon::now()->subDays(40))
            ->create(['contact_id' => $contact->id]);
        ContactMail::factory()->forNewsletter($newsletterB)->opened(Carbon::now()->subDays(2))
            ->create(['contact_id' => $contact->id]);

        $this->assertFalse(
            $this->service->contactMatchesFilters($contact, $this->engagementFilter('not_opened', null, 'last_n_days', 7))
        );
    }

    public function test_engagement_not_clicked_within_last_n_days(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $clickedRecently = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->clicked(Carbon::now()->subDays(2))
            ->create(['contact_id' => $clickedRecently->id]);

        $clickedLongAgo = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->clicked(Carbon::now()->subDays(40))
            ->create(['contact_id' => $clickedLongAgo->id]);

        $filters = $this->engagementFilter('not_clicked', null, 'last_n_days', 7);

        $this->assertFalse($this->service->contactMatchesFilters($clickedRecently, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($clickedLongAgo, $filters));
    }

    public function test_engagement_not_received_within_last_n_days(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $receivedRecently = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->delivered(Carbon::now()->subDays(2))
            ->create(['contact_id' => $receivedRecently->id]);

        $receivedLongAgo = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->delivered(Carbon::now()->subDays(40))
            ->create(['contact_id' => $receivedLongAgo->id]);

        $never = Contact::factory()->for($user)->create();

        $filters = $this->engagementFilter('not_received', null, 'last_n_days', 7);

        // "Did not receive in the last 7 days".
        $this->assertFalse($this->service->contactMatchesFilters($receivedRecently, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($receivedLongAgo, $filters));
        $this->assertTrue($this->service->contactMatchesFilters($never, $filters));
    }

    public function test_engagement_not_clicked_matches_opened_but_not_clicked(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        // Opened but never clicked — must match "did not click".
        $openedOnly = Contact::factory()->for($user)->create();
        ContactMail::factory()->forNewsletter($newsletter)->opened()
            ->create(['contact_id' => $openedOnly->id]);

        $this->assertTrue(
            $this->service->contactMatchesFilters($openedOnly, $this->engagementFilter('not_clicked'))
        );
    }
}
