<?php

namespace App\Support;

use App\Enums\NewsletterStatus;
use App\Models\Automation;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\Topic;
use App\Models\VisualAutomation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Finds the items that select contacts by a list or a topic: the filters of
 * newsletters, sequences, sequence steps and segments, and the triggers,
 * conditions and actions of automations. When the list or the topic goes
 * away, these items select a different audience or fail. Sent newsletters
 * do not count.
 *
 * @phpstan-type UsageItem array{id: int, name: string}
 * @phpstan-type Usage array{newsletters: list<UsageItem>, sequences: list<UsageItem>, segments: list<UsageItem>, automations: list<UsageItem>, visual_automations: list<UsageItem>}
 */
class AudienceUsage
{
    /**
     * The automation trigger, condition and action types that keep the id
     * of a list or a topic, by filter category.
     *
     * @var array<string, list<string>>
     */
    private const AUTOMATION_TYPES = [
        'lists' => ['contact_added_to_list', 'removed_from_list', 'add_list', 'remove_list', 'in_list', 'not_in_list'],
        'topics' => ['subscribed_to_topic', 'not_subscribed_to_topic'],
    ];

    /**
     * Find the items that use the list or one of its topics. The topics of
     * a list go away with the list.
     *
     * @return Usage
     */
    public static function ofList(MailList $list): array
    {
        return self::find([
            'lists' => [$list->id],
            'topics' => $list->topics()->pluck('id')->all(),
        ]);
    }

    /**
     * Find the items that use the topic.
     *
     * @return Usage
     */
    public static function ofTopic(Topic $topic): array
    {
        return self::find(['topics' => [$topic->id]]);
    }

    /**
     * @param  Usage  $usage
     */
    public static function isInUse(array $usage): bool
    {
        return collect($usage)->flatten(1)->isNotEmpty();
    }

    /**
     * Name each item, for example `newsletter "Spring sale" (#4)`.
     *
     * @param  Usage  $usage
     */
    public static function describe(array $usage): string
    {
        $labels = [
            'newsletters' => 'newsletter',
            'sequences' => 'sequence',
            'segments' => 'segment',
            'automations' => 'automation',
            'visual_automations' => 'visual automation',
        ];

        return collect($usage)
            ->flatMap(fn (array $items, string $type): array => array_map(
                fn (array $item): string => "{$labels[$type]} \"{$item['name']}\" (#{$item['id']})",
                $items,
            ))
            ->implode(', ');
    }

    /**
     * @param  array<string, list<int>>  $idsByCategory
     * @return Usage
     */
    private static function find(array $idsByCategory): array
    {
        $usesFilters = fn (mixed $filters): bool => self::filtersUse($filters, $idsByCategory);

        $newsletters = Newsletter::query()
            ->whereIn('status', NewsletterStatus::unfinished())
            ->whereNotNull('filters')
            ->orderBy('id')
            ->get(['id', 'title', 'filters'])
            ->filter(fn (Newsletter $newsletter): bool => $usesFilters($newsletter->filters))
            ->map(fn (Newsletter $newsletter): array => ['id' => $newsletter->id, 'name' => (string) $newsletter->title]);

        $stepSequenceIds = SequenceStep::query()
            ->whereIn('sequence_id', Sequence::query()->select('id'))
            ->whereNotNull('filters')
            ->get(['sequence_id', 'filters'])
            ->filter(fn (SequenceStep $step): bool => $usesFilters($step->filters))
            ->pluck('sequence_id');

        $sequences = Sequence::query()
            ->orderBy('id')
            ->get(['id', 'name', 'filters'])
            ->filter(fn (Sequence $sequence): bool => $usesFilters($sequence->filters) || $stepSequenceIds->contains($sequence->id))
            ->map(fn (Sequence $sequence): array => ['id' => $sequence->id, 'name' => (string) $sequence->name]);

        $segments = Segment::query()
            ->orderBy('id')
            ->get(['id', 'name', 'filters'])
            ->filter(fn (Segment $segment): bool => $usesFilters($segment->filters))
            ->map(fn (Segment $segment): array => ['id' => $segment->id, 'name' => (string) $segment->name]);

        $automations = Automation::query()
            ->orderBy('id')
            ->get(['id', 'name', 'condition', 'actions'])
            ->filter(fn (Automation $automation): bool => self::stepsUse([$automation->condition, ...($automation->actions ?? [])], $idsByCategory))
            ->map(fn (Automation $automation): array => ['id' => $automation->id, 'name' => (string) $automation->name]);

        $visualAutomations = VisualAutomation::query()
            ->orderBy('id')
            ->get(['id', 'name', 'workflow'])
            ->filter(fn (VisualAutomation $automation): bool => self::stepsUse(
                Arr::pluck((array) data_get($automation->workflow, 'nodes', []), 'data'),
                $idsByCategory,
            ))
            ->map(fn (VisualAutomation $automation): array => ['id' => $automation->id, 'name' => (string) $automation->name]);

        return [
            'newsletters' => self::items($newsletters),
            'sequences' => self::items($sequences),
            'segments' => self::items($segments),
            'automations' => self::items($automations),
            'visual_automations' => self::items($visualAutomations),
        ];
    }

    /**
     * Whether one of the filters selects one of the ids of its category.
     *
     * @param  array<string, list<int>>  $idsByCategory
     */
    private static function filtersUse(mixed $filters, array $idsByCategory): bool
    {
        foreach (is_array($filters) ? $filters : [] as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $ids = $idsByCategory[(string) ($filter['category'] ?? '')] ?? [];

            if ($ids !== [] && self::containsId($filter['values'] ?? [], $ids)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one of the automation steps keeps one of the ids. A step is
     * the condition or an action of an automation, or the data of a node of
     * a visual automation. Each step has a type and a value.
     *
     * @param  array<int, mixed>  $steps
     * @param  array<string, list<int>>  $idsByCategory
     */
    private static function stepsUse(array $steps, array $idsByCategory): bool
    {
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }

            $type = $step['type'] ?? $step['action_type'] ?? $step['condition_type'] ?? $step['trigger_type'] ?? null;

            foreach (self::AUTOMATION_TYPES as $category => $types) {
                if (in_array($type, $types, true) && self::containsId([$step['value'] ?? null], $idsByCategory[$category] ?? [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Filters and automations keep an id as an int or as a numeric string.
     *
     * @param  list<int>  $ids
     */
    private static function containsId(mixed $values, array $ids): bool
    {
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_numeric($value) && in_array((int) $value, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, UsageItem>  $items
     * @return list<UsageItem>
     */
    private static function items(Collection $items): array
    {
        return $items->values()->all();
    }
}
