<?php

namespace App\Http\Controllers;

use App\Models\Block;
use Illuminate\Http\JsonResponse;

class BlockListController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $blocks = Block::query()
            ->latest('id')
            ->get(['id', 'name']);

        return response()->json(['blocks' => $blocks]);
    }
}
