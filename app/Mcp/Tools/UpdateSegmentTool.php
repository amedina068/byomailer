<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateSegment;
use App\Concerns\SegmentValidationRules;
use App\Enums\NewsletterStatus;
use App\Enums\SequenceStatus;
use App\Mcp\Concerns\BuildsNewsletterFilters;
use App\Mcp\Concerns\ConfirmsLiveEdits;
use App\Mcp\Concerns\FormatsSegment;
use App\Models\Newsletter;
use App\Models\Segment;
use App\Models\Sequence;
use App\Models\User;
use App\Rules\AudienceFilters;
use App\Services\ContactFilterService;
use App\Services\FilterPreparationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a saved segment of the authenticated account. Only the fields that you pass change. Pass filters to replace all the filters of the segment: 1 to 20 filters in the view-newsletter-filters-tool format. Newsletters and sequences that use the segment read its filters when they send. If the new filters change the audience of a scheduled or sending newsletter or a live sequence, the first call saves nothing and returns a preview. The preview has the current and new filters, the current and new contact counts, the live newsletters and sequences, and the effects. Show the preview to the user. After the user confirms, call the tool again with the same arguments and confirm_live set to true. A change to the name or the description only needs no confirmation. Find segment ids with list-segments.')]
class UpdateSegmentTool extends Tool
{
    use BuildsNewsletterFilters;
    use ConfirmsLiveEdits;
    use FormatsSegment;
    use SegmentValidationRules;

    protected string $name = 'update-segment';

    public function __construct(
        private readonly ContactFilterService $contactFilterService,
        private readonly FilterPreparationService $filterPreparationService,
    ) {}

    public function handle(Request $request, UpdateSegment $updateSegment): Response|ResponseFactory
    {
        /** @var User $user */
        $user = $request->user();

        $segmentId = $request->validate(['segment_id' => ['required', 'integer']])['segment_id'];
        $segment = Segment::query()->find($segmentId);

        if ($segment === null) {
            return Response::error("No segment found with id {$segmentId}.");
        }

        $validated = $request->validate($this->updateRules($user, $segment), $this->segmentMessages());

        if (array_key_exists('filters', $validated) && ! $this->confirmsLive($validated)) {
            $preview = $this->liveAudiencePreview($user, $segment, $validated['filters']);

            if ($preview !== null) {
                return $preview;
            }
        }

        $updateSegment->handle($user, $segment, Arr::only($validated, ['name', 'description', 'filters']));

        return Response::structured([
            ...$this->segmentSummary($segment->refresh(), $this->contactFilterService),
            'applied' => true,
            'message' => "Segment #{$segment->id} updated.",
        ]);
    }

    /**
     * The rules of the web form. Each field is optional, and a field that the
     * caller sends must be valid.
     *
     * @return array<string, mixed>
     */
    private function updateRules(User $user, Segment $segment): array
    {
        $rules = array_map(
            fn (array $fieldRules): array => ['sometimes', ...$fieldRules],
            $this->segmentRules($user->client_id, $segment),
        );
        $rules['filters'][] = new AudienceFilters;

        return [
            'segment_id' => ['required', 'integer'],
            ...$this->filterValidationRules(),
            ...$rules,
            ...$this->confirmLiveValidationRules(),
        ];
    }

    /**
     * Return a preview when the new filters change the audience of a live
     * record. Return null when the filters do not change or no live record
     * uses the segment.
     *
     * @param  array<array-key, mixed>  $filters
     */
    private function liveAudiencePreview(User $user, Segment $segment, array $filters): ?ResponseFactory
    {
        $newFilters = $this->filterPreparationService->prepareFilters($filters, $user);

        if ($newFilters === ($segment->filters ?? [])) {
            return null;
        }

        $newsletters = $segment->newsletters()
            ->whereIn('status', NewsletterStatus::scheduledOrSending())
            ->orderBy('id')
            ->get();
        $sequences = $segment->sequences()
            ->where('status', SequenceStatus::Live)
            ->orderBy('id')
            ->get();

        if ($newsletters->isEmpty() && $sequences->isEmpty()) {
            return null;
        }

        return $this->statusChangePreview([
            ...$this->segmentSummary($segment, $this->contactFilterService),
            'change' => 'filters',
            'new_filters' => $newFilters,
            'new_contacts_count' => $this->contactFilterService->segmentContactsQuery($segment->client_id, $newFilters)->count(),
            'live_newsletters' => $newsletters->map(fn (Newsletter $newsletter): array => [
                'id' => $newsletter->id,
                'title' => $newsletter->title,
                'status' => $newsletter->status->value,
                'scheduled_at' => $newsletter->scheduled_at?->toIso8601String(),
            ])->all(),
            'live_sequences' => $sequences->map(fn (Sequence $sequence): array => [
                'id' => $sequence->id,
                'name' => $sequence->name,
                'status' => $sequence->status->value,
            ])->all(),
            'effects' => $this->effects($newsletters, $sequences),
        ]);
    }

    /**
     * @param  Collection<int, Newsletter>  $newsletters
     * @param  Collection<int, Sequence>  $sequences
     * @return list<string>
     */
    private function effects(Collection $newsletters, Collection $sequences): array
    {
        $effects = [];

        foreach ($newsletters as $newsletter) {
            $effects[] = "Newsletter #{$newsletter->id} ({$newsletter->status->value}) uses this segment. The mails that it did not send yet go to the contacts that match the new filters.";
        }

        foreach ($sequences as $sequence) {
            $effects[] = "Live sequence #{$sequence->id} uses this segment. Enrolled contacts that do not match the new filters leave the sequence before their next mail. New contacts join it only when they match.";
        }

        return $effects;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'segment_id' => $schema->integer()
                ->description('Id of the segment to update. Use list-segments to find segment ids.')
                ->required(),
            'name' => $schema->string()
                ->description('New name of the segment. It must be unique in the account.'),
            'description' => $schema->string()
                ->description('New description of the segment. Send null to clear it.'),
            ...$this->filtersSchema($schema, 'New segment filters, 1 to 20. The array replaces all the current filters. Use view-newsletter-filters-tool to build it.'),
            ...$this->confirmLiveSchema($schema),
        ];
    }
}
