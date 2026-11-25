<?php

namespace App\Concerns;

use App\Models\MailList;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait MailListValidationRules
{
    /**
     * Get the validation rules of the list name. The web forms and the API
     * use them. A name is unique in the client. The database has a unique
     * index on `client_id` and `name`.
     *
     * @return array<string, array<int, Unique|string>>
     */
    protected function mailListRules(int $clientId, int|string|null $ignoreListId = null): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(MailList::class, 'name')
                    ->where('client_id', $clientId)
                    ->ignore($ignoreListId),
            ],
        ];
    }
}
