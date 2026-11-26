<?php

namespace App\Http\Requests;

use App\Concerns\ProjectValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    use ProjectValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->projectRules($this->user()?->client_id, $this->ignoreId());
    }

    protected function ignoreId(): ?int
    {
        return null;
    }
}
