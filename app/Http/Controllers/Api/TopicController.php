<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateTopic;
use App\Actions\DeleteTopic;
use App\Actions\UpdateTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\TopicRequest;
use App\Http\Resources\TopicResource;
use App\Models\MailList;
use App\Models\Topic;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Topics', 'A topic is a subject inside one list. A contact subscribes to each topic of the list, and can unsubscribe from one topic on the preference page.', weight: 3)]
class TopicController extends Controller
{
    /**
     * List the topics of a list.
     *
     * Returns the topics of one list, newest first.
     */
    public function index(Request $request, int $listId): JsonResponse
    {
        $list = MailList::query()
            ->findOrFail($listId);

        $topics = $list->topics()->latest('id')->get();

        return TopicResource::collection($topics)->response();
    }

    /**
     * Create a topic.
     *
     * Creates a topic in the list. Each contact on the list is subscribed to the
     * new topic in the background.
     */
    public function store(TopicRequest $request, int $listId, CreateTopic $createTopic): JsonResponse
    {
        $list = MailList::query()
            ->findOrFail($listId);

        $topic = $createTopic->handle($request->user(), $list, $request->validated());

        return TopicResource::make($topic)->response()->setStatusCode(201);
    }

    /**
     * Update a topic.
     *
     * Changes the name and the description of a topic.
     */
    public function update(TopicRequest $request, int $listId, int $topicId, UpdateTopic $updateTopic): JsonResponse
    {
        $topic = Topic::query()
            ->where('list_id', $listId)
            ->findOrFail($topicId);

        $updateTopic->handle($topic, $request->validated());

        return TopicResource::make($topic)->response();
    }

    /**
     * Delete a topic.
     *
     * Deletes the topic from the list. When newsletters, sequences, segments
     * or automations use the topic, the request fails with 422 and names them.
     */
    public function destroy(int $listId, int $topicId, DeleteTopic $deleteTopic): JsonResponse
    {
        $topic = Topic::query()
            ->where('list_id', $listId)
            ->findOrFail($topicId);

        $deleteTopic->handle($topic);

        return response()->json(null, 204);
    }
}
