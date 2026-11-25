<?php

namespace App\Http\Controllers;

use App\Models\ContactImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactImportStatusController extends Controller
{
    public function __invoke(Request $request, $contactImportId): JsonResponse
    {
        $contactImport = ContactImport::query()->findOrFail($contactImportId);

        $batch = $contactImport->batch();
        $progress = $contactImport->total_count > 0
            ? (int) round((($contactImport->processed_count + $contactImport->skipped_count) / $contactImport->total_count) * 100)
            : 0;

        return response()->json([
            'id' => $contactImport->id,
            'status' => $contactImport->status,
            'original_filename' => $contactImport->original_filename,
            'total_count' => $contactImport->total_count,
            'processed_count' => $contactImport->processed_count,
            'skipped_count' => $contactImport->skipped_count,
            'error_message' => $contactImport->error_message,
            'progress' => max(min($progress, 100), 0),
            'batch' => $batch ? [
                'id' => $batch->id,
                'total_jobs' => $batch->totalJobs,
                'pending_jobs' => $batch->pendingJobs,
                'processed_jobs' => $batch->processedJobs(),
                'failed_jobs' => $batch->failedJobs,
                'finished' => $batch->finished(),
            ] : null,
        ]);
    }
}
