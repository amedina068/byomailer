<?php

namespace App\Http\Controllers;

use App\Actions\CreateTag;
use App\Http\Requests\TagRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    public function index(Request $request): Response
    {
        $tags = Tag::query()
            ->withCount('contacts')
            ->latest('id')
            ->get();

        return Inertia::render('tags/Index', [
            'tags' => TagResource::collection($tags)->resolve($request),
        ]);
    }

    public function store(TagRequest $request, CreateTag $createTag): RedirectResponse
    {
        $tag = $createTag->handle($request->user(), $request->validated('name'));

        return back()->with('createdTag', [
            'id' => $tag->id,
            'name' => $tag->name,
        ]);
    }
}
