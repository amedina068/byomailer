<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ValidatesProjectIds
{
    /**
     * Validation rules for optionally assigning client-owned projects.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function projectIdRules(): array
    {
        return [
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => [
                'integer',
                Rule::exists('projects', 'id')->where('client_id', $this->user()?->client_id),
            ],
        ];
    }
}
