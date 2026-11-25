<?php

namespace App\Http\Requests\Api;

use App\Http\Requests\Concerns\HasUniqueProjectNameRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    use HasUniqueProjectNameRule;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                $this->uniqueNameForClient($this->ignoreId()),
            ],
        ];
    }

    protected function ignoreId(): ?int
    {
        return null;
    }
}
