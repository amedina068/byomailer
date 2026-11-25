<?php

namespace App\Http\Requests;

class UpdateProjectRequest extends StoreProjectRequest
{
    protected function ignoreId(): ?int
    {
        return $this->route('project')?->id;
    }
}
