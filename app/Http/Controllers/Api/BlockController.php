<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateBlock;
use App\Actions\DeleteBlock;
use App\Actions\UpdateBlock;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BlockRequest;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Blocks', 'A block is a reusable piece of HTML content.', weight: 7)]
class BlockController extends Controller
{
    /**
     * List blocks.
     *
     * Returns all the blocks of your client, newest first. This list has no
     * pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $blocks = Block::query()
            ->latest('id')
            ->get();

        return BlockResource::collection($blocks)->response();
    }

    /**
     * Get a block.
     *
     * Returns one block with its HTML content.
     */
    public function show(Request $request, int $blockId): JsonResponse
    {
        $block = Block::query()->findOrFail($blockId);

        return BlockResource::make($block)->response();
    }

    /**
     * Create a block.
     *
     * Creates a block with a name and HTML content.
     */
    public function store(BlockRequest $request, CreateBlock $createBlock): JsonResponse
    {
        $block = $createBlock->handle($request->user(), $request->validated('name'), $request->validated('html_content'));

        return BlockResource::make($block)->response()->setStatusCode(201);
    }

    /**
     * Update a block.
     *
     * Replaces the name and the HTML content of a block.
     */
    public function update(BlockRequest $request, int $blockId, UpdateBlock $updateBlock): JsonResponse
    {
        $block = $updateBlock->handle(
            Block::query()->findOrFail($blockId),
            $request->validated('name'),
            $request->validated('html_content'),
        );

        return BlockResource::make($block)->response();
    }

    /**
     * Delete a block.
     *
     * Deletes the block.
     */
    public function destroy(int $blockId, DeleteBlock $deleteBlock): JsonResponse
    {
        $deleteBlock->handle(Block::query()->findOrFail($blockId));

        return response()->json(null, 204);
    }
}
