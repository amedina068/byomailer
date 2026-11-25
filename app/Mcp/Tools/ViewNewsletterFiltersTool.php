<?php

namespace App\Mcp\Tools;

use App\Enums\FilterCategory;
use App\Enums\FilterMode;
use App\Models\Topic;
use App\Models\User;
use App\Services\FilterPreparationService;
use App\Support\Filters\FilterSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Describe the audience targeting filters available when creating a newsletter for the authenticated account. Returns the filter schema (categories, operators, modes) plus the account\'s concrete building blocks: tags, lists, topics, attribute keys, countries, timezones and newsletters that can be referenced by id/name in a filter. Use the result to assemble the "filters" array for the calculate-newsletter-contacts-tool and create-newsletter-tool tools.')]
class ViewNewsletterFiltersTool extends Tool
{
    public function __construct(private readonly FilterPreparationService $filterPreparationService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();
        $client = $user->client;

        $options = $this->filterPreparationService->filterOptionData($client->id);

        return Response::structured([
            'schema' => [
                'categories' => array_column(FilterCategory::cases(), 'value'),
                'mode_categories' => array_column(FilterCategory::withMode(), 'value'),
                'operator_categories' => array_column(FilterCategory::withOperator(), 'value'),
                'modes' => array_column(FilterMode::cases(), 'value'),
                'attribute_operators' => FilterSchema::ATTRIBUTE_OPERATORS,
                'date_operators' => FilterSchema::DATE_OPERATORS,
                'engagement_operators' => FilterSchema::ENGAGEMENT_OPERATORS,
                'engagement_windows' => FilterSchema::ENGAGEMENT_WINDOWS,
                'location_fields' => FilterSchema::LOCATION_FIELDS,
                'location_modes' => array_column(FilterMode::forLocation(), 'value'),
            ],
            'available' => [
                'tags' => $client->tags()->orderBy('name')->pluck('name')->all(),
                'lists' => $client->mailingLists()->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($list): array => ['id' => $list->id, 'name' => $list->name])->all(),
                'topics' => Topic::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Topic $topic): array => ['id' => $topic->id, 'name' => $topic->name])->all(),
                'attributes' => $client->contactAttributes()->orderBy('name')
                    ->pluck('name')->filter()->unique()->values()->all(),
                'countries' => $options['countries'],
                'timezones' => $options['timezones'],
                'newsletters' => $options['newsletters'],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
