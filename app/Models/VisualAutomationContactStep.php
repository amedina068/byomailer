<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisualAutomationContactStep extends Model
{
    const STATUS_PENDING = 'pending';

    const STATUS_WAITING = 'waiting';

    const STATUS_COMPLETED = 'completed';

    const STATUS_SKIPPED = 'skipped';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wait_until' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VisualAutomationEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(VisualAutomationEnrollment::class, 'enrollment_id');
    }
}
