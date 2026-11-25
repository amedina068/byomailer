<?php

namespace App\Http\Controllers;

use App\Actions\CreateBlock;
use App\Actions\DeleteBlock;
use App\Actions\UpdateBlock;
use App\Http\Requests\StoreBlockRequest;
use App\Http\Requests\UpdateBlockRequest;
use App\Http\Resources\BlockResource;
use App\Models\Block;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlockController extends Controller
{
    public function index(Request $request): Response
    {
        $blocks = Block::query()
            ->latest('id')
            ->get();

        return Inertia::render('blocks/Index', [
            'blocks' => BlockResource::collection($blocks)->resolve($request),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('blocks/Create');
    }

    public function store(StoreBlockRequest $request, CreateBlock $createBlock): RedirectResponse
    {
        $createBlock->handle($request->user(), $request->validated('name'), $request->validated('html_content'));

        return to_route('blocks.index');
    }

    public function edit(int $block): Response
    {
        $block = Block::findOrFail($block);

        return Inertia::render('blocks/Edit', [
            'block' => BlockResource::make($block)->resolve(),
        ]);
    }

    public function update(UpdateBlockRequest $request, int $block, UpdateBlock $updateBlock): RedirectResponse
    {
        $updateBlock->handle(
            Block::findOrFail($block),
            $request->validated('name'),
            $request->validated('html_content'),
        );

        return to_route('blocks.index');
    }

    public function destroy(int $block, DeleteBlock $deleteBlock): RedirectResponse
    {
        $deleteBlock->handle(Block::findOrFail($block));

        return to_route('blocks.index');
    }
}
