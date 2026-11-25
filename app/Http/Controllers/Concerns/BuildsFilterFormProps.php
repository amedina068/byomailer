<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Resources\MailListResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\TopicResource;
use App\Models\ContactAttribute;
use App\Models\MailList;
use App\Models\Tag;
use App\Models\Topic;
use App\Services\FilterPreparationService;
use Illuminate\Http\Request;

/**
 * Picker options for the audience filter form. The sequence page and the
 * sequence step page show the same form, so they share one source.
 */
trait BuildsFilterFormProps
{
    /**
     * @return array{
     *     lists: array<int, mixed>,
     *     tags: array<int, mixed>,
     *     topics: array<int, mixed>,
     *     contactAttributes: array<int, string>,
     *     filterNewsletters: array<int, array{id: int, title: string}>,
     *     filterCountries: array<int, string>,
     *     filterTimezones: array<int, string>,
     * }
     */
    protected function filterFormProps(Request $request, FilterPreparationService $filterPreparationService): array
    {
        $user = $request->user();
        $filterOptions = $filterPreparationService->filterOptionData($user->client_id);

        return [
            'lists' => MailListResource::collection(MailList::query()->orderBy('name')->get())->resolve($request),
            'tags' => TagResource::collection(Tag::query()->orderBy('name')->get())->resolve($request),
            'topics' => TopicResource::collection(
                Topic::query()->with(['list:id,name'])->orderBy('name')->get()
            )->resolve($request),
            'contactAttributes' => ContactAttribute::query()
                ->orderBy('name')
                ->pluck('name')
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'filterNewsletters' => $filterOptions['newsletters'],
            'filterCountries' => $filterOptions['countries'],
            'filterTimezones' => $filterOptions['timezones'],
        ];
    }
}
