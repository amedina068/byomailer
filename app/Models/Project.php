<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUuid;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use BelongsToClient, HasFactory, HasUuid;

    protected $guarded = [];

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphToMany<Newsletter, $this>
     */
    public function newsletters(): MorphToMany
    {
        return $this->morphedByMany(Newsletter::class, 'projectable')->withTimestamps();
    }

    /**
     * @return MorphToMany<Sequence, $this>
     */
    public function sequences(): MorphToMany
    {
        return $this->morphedByMany(Sequence::class, 'projectable')->withTimestamps();
    }

    /**
     * @return MorphToMany<Automation, $this>
     */
    public function automations(): MorphToMany
    {
        return $this->morphedByMany(Automation::class, 'projectable')->withTimestamps();
    }

    /**
     * @return MorphToMany<VisualAutomation, $this>
     */
    public function visualAutomations(): MorphToMany
    {
        return $this->morphedByMany(VisualAutomation::class, 'projectable')->withTimestamps();
    }
}
