<?php

namespace App\Models;

use Database\Factories\VisualAutomationEnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisualAutomationEnrollment extends Model
{
    /** @use HasFactory<VisualAutomationEnrollmentFactory> */
    use HasFactory;

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
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VisualAutomation, $this>
     */
    public function visualAutomation(): BelongsTo
    {
        return $this->belongsTo(VisualAutomation::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<VisualAutomationContactStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(VisualAutomationContactStep::class, 'enrollment_id');
    }
}
