<?php

namespace App\Actions;

use App\Models\Project;

class DeleteProject
{
    /**
     * Delete the project. The foreign key removes its `projectables` rows.
     * The newsletters, sequences and automations in it stay.
     */
    public function handle(Project $project): void
    {
        $project->delete();
    }
}
