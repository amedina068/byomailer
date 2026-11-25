<?php

namespace App\Http\Requests\Api;

class UpdateProjectRequest extends StoreProjectRequest
{
    protected function ignoreId(): ?int
    {
        $projectId = $this->route('projectId');

        return $projectId !== null ? (int) $projectId : null;
    }
}
