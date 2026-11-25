<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Automation;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\VisualAutomation;
use Illuminate\Support\Facades\DB;

trait SyncsProjectAssignments
{
    /**
     * Sync the model's project assignments when the validated payload includes project_ids.
     *
     * @param  array<string, mixed>  $data
     */
    protected function syncProjectsWhenPresent(Automation|Newsletter|Sequence|VisualAutomation $model, array $data): void
    {
        if (array_key_exists('project_ids', $data)) {
            DB::transaction(fn () => $model->syncProjects(array_map('intval', $data['project_ids'])));
        }
    }
}
