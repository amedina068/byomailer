<?php

namespace App\Models\Concerns;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasProjects
{
    public static function bootHasProjects(): void
    {
        static::deleting(function (Model $model): void {
            $model->projects()->detach();
        });
    }

    /**
     * @return MorphToMany<Project, $this>
     */
    public function projects(): MorphToMany
    {
        return $this->morphToMany(Project::class, 'projectable')->withTimestamps();
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    public function syncProjects(array $projectIds): void
    {
        $this->projects()->sync($projectIds);
    }
}
