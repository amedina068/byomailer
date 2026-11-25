<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateMailList;
use App\Actions\DeleteMailList;
use App\Actions\UpdateMailList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MailListRequest;
use App\Http\Resources\MailListResource;
use App\Models\MailList;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

#[Group('Lists', 'A list groups contacts. When a list has opt-in, the contact gets a confirmation email and is subscribed only after the confirmation.', weight: 2)]
class MailListController extends Controller
{
    /**
     * List lists.
     *
     * Returns the lists of your client, newest first.
     */
    #[QueryParameter('cursor', description: 'The cursor from `meta.next_cursor` or `meta.prev_cursor` of the previous response.', type: 'string')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $lists = MailList::query()
            ->latest('id')
            ->cursorPaginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return MailListResource::collection($lists);
    }

    /**
     * Create a list.
     *
     * Creates a list with the given name. A list name is unique in your account.
     */
    public function store(MailListRequest $request, CreateMailList $createMailList): JsonResponse
    {
        $list = $createMailList->handle($request->user(), $request->validated('name'));

        return MailListResource::make($list)->response()->setStatusCode(201);
    }

    /**
     * Rename a list.
     *
     * Changes the name of a list. A list name is unique in your account.
     */
    public function update(MailListRequest $request, int $listId, UpdateMailList $updateMailList): JsonResponse
    {
        $list = MailList::query()->findOrFail($listId);

        $updateMailList->handle($request->user(), $list, ['name' => $request->validated('name')]);

        return MailListResource::make($list)->response();
    }

    /**
     * Delete a list.
     *
     * Deletes the list and its topics. The contacts on the list are not
     * deleted. When newsletters, sequences, segments or automations use the
     * list or one of its topics, the request fails with 422 and names them.
     */
    public function destroy(int $listId, DeleteMailList $deleteMailList): Response
    {
        $list = MailList::query()->findOrFail($listId);

        $deleteMailList->handle($list);

        return response()->noContent();
    }
}
