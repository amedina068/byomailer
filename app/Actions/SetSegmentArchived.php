<?php

namespace App\Actions;

use App\Models\Segment;

class SetSegmentArchived
{
    /**
     * Archive or restore a segment. An archived segment is not in the
     * pickers and in the default list. Newsletters and sequences that use it
     * keep its filters, so the change sends no mail and stops no mail.
     */
    public function handle(Segment $segment, bool $archived): Segment
    {
        $segment->update(['archived_at' => $archived ? now() : null]);

        return $segment;
    }
}
