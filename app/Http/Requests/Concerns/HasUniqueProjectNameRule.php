<?php

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use Closure;

trait HasUniqueProjectNameRule
{
    protected function uniqueNameForClient(?int $ignoreId = null): Closure
    {
        $clientId = $this->user()?->client_id;

        return function (string $attribute, mixed $value, Closure $fail) use ($clientId, $ignoreId) {
            $exists = Project::withoutGlobalScopes()
                ->where('client_id', $clientId)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($exists) {
                $fail('A project with this name already exists.');
            }
        };
    }
}
