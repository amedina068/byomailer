<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectIds;
use Illuminate\Foundation\Http\FormRequest;

class SyncProjectsRequest extends FormRequest
{
    use ValidatesProjectIds;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->projectIdRules();
    }

    /**
     * @return array<int, int>
     */
    public function projectIds(): array
    {
        return array_map('intval', $this->validated('project_ids', []));
    }
}
