<?php

namespace App\Concerns;

use App\Models\Project;
use Closure;

trait ProjectValidationRules
{
    /**
     * Get the validation rules of a project. The web form, the API and the
     * MCP tools use them. A name is unique in the client. The comparison
     * ignores the letter case.
     *
     * @return array<string, array<int, Closure|string>>
     */
    protected function projectRules(?int $clientId, ?int $ignoreId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                $this->uniqueProjectName($clientId, $ignoreId),
            ],
        ];
    }

    private function uniqueProjectName(?int $clientId, ?int $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($clientId, $ignoreId): void {
            $exists = Project::withoutGlobalScopes()
                ->where('client_id', $clientId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                $fail('A project with this name already exists.');
            }
        };
    }
}
