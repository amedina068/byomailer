<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateTag;
use App\Actions\DeleteTag;
use App\Actions\RenameTag;
use App\Http\Controllers\Controller;
use App\Http\Requests\TagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

#[Group('Tags', 'A tag labels contacts. Use tags to select the audience of a newsletter or a sequence.', weight: 4)]
class TagController extends Controller
{
    /**
     * List tags.
     *
     * Returns all the tags of your account, newest first. This list has no
     * pagination.
     */
    public function index(): JsonResponse
    {
        $tags = Tag::query()
            ->latest('id')
            ->get();

        return TagResource::collection($tags)->response();
    }

    /**
     * Create a tag.
     *
     * Creates a tag. A tag name is unique in your account.
     */
    public function store(TagRequest $request, CreateTag $createTag): JsonResponse
    {
        $tag = $createTag->handle($request->user(), $request->validated('name'));

        return TagResource::make($tag)->response()->setStatusCode(201);
    }

    /**
     * Rename a tag.
     *
     * Changes the name of a tag. Tag filters select tags by name. When a saved
     * filter of a newsletter that can still send, a sequence, a sequence step or
     * a segment uses the tag, the response is 422.
     */
    public function update(TagRequest $request, Tag $tag, RenameTag $renameTag): JsonResponse
    {
        $tag = $renameTag->handle($tag, $request->validated('name'));

        return TagResource::make($tag)->response();
    }

    /**
     * Delete a tag.
     *
     * Deletes the tag and removes it from each contact. The removal runs the
     * live tag automations of your client. When a saved filter uses the tag,
     * the response is 422, as for a rename.
     */
    public function destroy(Tag $tag, DeleteTag $deleteTag): Response
    {
        $deleteTag->handle($tag);

        return response()->noContent();
    }
}
