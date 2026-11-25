<?php

namespace App\Http\Controllers;

use App\Http\Requests\EditorImageUploadRequest;
use App\Services\UserFilesystemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EditorImageController extends Controller
{
    public function store(EditorImageUploadRequest $request, UserFilesystemManager $filesystemManager): JsonResponse
    {
        $file = $request->file('image');
        $disk = $filesystemManager->diskFor($request->user());

        $path = $file->storeAs('editor-images', Str::uuid().'.'.$file->guessExtension(), $disk);

        return response()->json([
            'url' => Storage::disk($disk)->url($path),
        ]);
    }
}
