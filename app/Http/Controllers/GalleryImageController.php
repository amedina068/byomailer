<?php

namespace App\Http\Controllers;

use App\Http\Requests\GalleryImageUploadRequest;
use App\Models\User;
use App\Services\UserFilesystemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class GalleryImageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientUserIds = $request->user()->client->users()->pluck('id');

        $images = Media::query()
            ->where('model_type', User::class)
            ->whereIn('model_id', $clientUserIds)
            ->where('collection_name', 'gallery')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Media $media) => [
                'uuid' => $media->uuid,
                'name' => $media->name,
                'url' => $media->getUrl(),
                'created_at' => $media->created_at->toISOString(),
            ]);

        return response()->json(['images' => $images]);
    }

    public function store(GalleryImageUploadRequest $request, UserFilesystemManager $filesystemManager): JsonResponse
    {
        $user = $request->user();
        $disk = $filesystemManager->diskFor($user);

        $media = $user
            ->addMediaFromRequest('image')
            ->usingFileName(Str::uuid().'.'.$request->file('image')->guessExtension())
            ->toMediaCollection('gallery', $disk);

        return response()->json([
            'uuid' => $media->uuid,
            'name' => $media->name,
            'url' => $media->getUrl(),
            'created_at' => $media->created_at->toISOString(),
        ], 201);
    }
}
