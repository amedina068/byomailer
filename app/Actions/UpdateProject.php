<?php

namespace App\Actions;

use App\Models\Project;

class UpdateProject
{
    /**
     * Rename the project.
     */
    public function handle(Project $project, string $name): Project
    {
        $project->update(['name' => $name]);

        return $project;
    }
}
