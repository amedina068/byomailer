<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSegmentArchiveRequest;
use App\Models\Segment;
use Illuminate\Http\RedirectResponse;

class SegmentArchiveController extends Controller
{
    /**
     * Archive or restore a segment. Archiving hides it from the pickers and the
     * default index without touching any live reference — newsletters and
     * sequences pointing at it keep resolving their audience unchanged.
     */
    public function __invoke(UpdateSegmentArchiveRequest $request, Segment $segment): RedirectResponse
    {
        $archived = $request->validated('archived');

        $segment->update(['archived_at' => $archived ? now() : null]);

        return back()->with('success', $archived ? 'Segment archived.' : 'Segment restored.');
    }
}
