<?php

namespace App\Http\Controllers;

use App\Actions\CreateTopic;
use App\Actions\DeleteTopic;
use App\Actions\UpdateTopic;
use App\Http\Requests\TopicRequest;
use App\Models\MailList;
use App\Models\Topic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class TopicController extends Controller
{
    public function store(TopicRequest $request, int $listId, CreateTopic $createTopic): RedirectResponse
    {
        $list = MailList::query()
            ->findOrFail($listId);

        $createTopic->handle($request->user(), $list, $request->validated());

        return to_route('lists.show', $list->id);
    }

    public function update(TopicRequest $request, int $listId, int $topicId, UpdateTopic $updateTopic): RedirectResponse
    {
        $list = MailList::query()
            ->findOrFail($listId);

        $updateTopic->handle(Topic::query()->where('list_id', $list->id)->findOrFail($topicId), $request->validated());

        return to_route('lists.show', $list->id);
    }

    /**
     * The list page shows the reason of a refused delete as an error toast.
     */
    public function destroy(int $listId, int $topicId, DeleteTopic $deleteTopic): RedirectResponse
    {
        $list = MailList::query()
            ->findOrFail($listId);

        try {
            $deleteTopic->handle(Topic::query()->where('list_id', $list->id)->findOrFail($topicId));
        } catch (ValidationException $exception) {
            return to_route('lists.show', $list->id)->with('error', $exception->getMessage());
        }

        return to_route('lists.show', $list->id);
    }
}
