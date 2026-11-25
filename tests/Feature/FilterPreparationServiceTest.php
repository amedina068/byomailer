<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\FilterPreparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilterPreparationServiceTest extends TestCase
{
    use RefreshDatabase;

    private FilterPreparationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FilterPreparationService::class);
    }

    public function test_date_filter_with_fixed_date_is_prepared(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'date', 'operator' => 'before', 'values' => ['date' => '2026-03-01']],
        ], $user);

        $this->assertCount(1, $prepared);
        $this->assertSame('date', $prepared[0]['category']);
        $this->assertSame('before', $prepared[0]['operator']);
        $this->assertSame(['date' => '2026-03-01'], $prepared[0]['values']);
    }

    public function test_date_filter_with_invalid_date_is_dropped(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'date', 'operator' => 'before', 'values' => ['date' => 'not-a-date']],
        ], $user);

        $this->assertSame([], $prepared);
    }

    public function test_date_in_last_filter_clamps_days(): void
    {
        $user = User::factory()->create();

        $valid = $this->service->prepareFilters([
            ['category' => 'date', 'operator' => 'in_last', 'values' => ['days' => 30]],
        ], $user);
        $this->assertSame(['days' => 30], $valid[0]['values']);

        $tooBig = $this->service->prepareFilters([
            ['category' => 'date', 'operator' => 'in_last', 'values' => ['days' => 999999]],
        ], $user);
        $this->assertSame([], $tooBig);
    }

    public function test_date_between_requires_ordered_range(): void
    {
        $user = User::factory()->create();

        $reversed = $this->service->prepareFilters([
            ['category' => 'date', 'operator' => 'between', 'values' => ['from' => '2026-05-01', 'to' => '2026-01-01']],
        ], $user);

        $this->assertSame([], $reversed);
    }

    public function test_engagement_filter_keeps_owned_newsletter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create();

        $prepared = $this->service->prepareFilters([
            [
                'category' => 'engagement',
                'operator' => 'opened',
                'values' => ['newsletter_id' => $newsletter->id, 'within' => 'last_n_days', 'days' => 14],
            ],
        ], $user);

        $this->assertCount(1, $prepared);
        $this->assertSame($newsletter->id, $prepared[0]['values']['newsletter_id']);
        $this->assertSame('last_n_days', $prepared[0]['values']['within']);
        $this->assertSame(14, $prepared[0]['values']['days']);
    }

    public function test_engagement_filter_dropped_for_unknown_newsletter(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);

        $prepared = $this->service->prepareFilters([
            [
                'category' => 'engagement',
                'operator' => 'opened',
                'values' => ['newsletter_id' => 999999, 'within' => 'ever', 'days' => null],
            ],
        ], $user);

        $this->assertSame([], $prepared);
    }

    public function test_location_filter_is_prepared(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'location', 'field' => 'country', 'mode' => 'any', 'values' => ['US', 'GB', '']],
        ], $user);

        $this->assertCount(1, $prepared);
        $this->assertSame(['US', 'GB'], $prepared[0]['values']);
    }

    public function test_location_filter_rejects_invalid_field(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'location', 'field' => 'planet', 'mode' => 'any', 'values' => ['US']],
        ], $user);

        $this->assertSame([], $prepared);
    }

    public function test_attribute_operator_defaults_to_equals(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'attributes', 'mode' => 'all', 'values' => [['key' => 'plan', 'value' => 'pro']]],
        ], $user);

        $this->assertSame('equals', $prepared[0]['values'][0]['operator']);
    }

    public function test_attribute_valueless_operator_keeps_filter_without_value(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'attributes', 'mode' => 'all', 'values' => [
                ['key' => 'vip', 'operator' => 'is_set', 'value' => ''],
            ]],
        ], $user);

        $this->assertCount(1, $prepared);
        $this->assertSame('is_set', $prepared[0]['values'][0]['operator']);
    }

    public function test_attribute_numeric_operator_requires_numeric_value(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'attributes', 'mode' => 'all', 'values' => [
                ['key' => 'age', 'operator' => 'gt', 'value' => 'old'],
            ]],
        ], $user);

        $this->assertSame([], $prepared);
    }

    public function test_attribute_key_with_unsafe_characters_is_dropped(): void
    {
        $user = User::factory()->create();

        $prepared = $this->service->prepareFilters([
            ['category' => 'attributes', 'mode' => 'all', 'values' => [
                ['key' => 'plan"; drop', 'operator' => 'equals', 'value' => 'pro'],
            ]],
        ], $user);

        $this->assertSame([], $prepared);
    }

    public function test_filter_option_data_returns_client_scoped_lists(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $newsletter = Newsletter::factory()->for($user)->create(['title' => 'Spring update']);
        Contact::factory()->for($user)->create(['country' => 'US', 'timezone' => 'America/New_York']);
        Contact::factory()->for($user)->create(['country' => 'GB', 'timezone' => null]);

        $other = User::factory()->create();
        Newsletter::factory()->for($other)->create(['title' => 'Other client']);

        $data = $this->service->filterOptionData($user->client_id);

        $this->assertSame([['id' => $newsletter->id, 'title' => 'Spring update']], $data['newsletters']);
        $this->assertSame(['GB', 'US'], $data['countries']);
        $this->assertSame(['America/New_York'], $data['timezones']);
    }
}
