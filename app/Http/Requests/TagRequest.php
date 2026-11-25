<?php

namespace App\Http\Requests;

use App\Concerns\TagValidationRules;
use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class TagRequest extends FormRequest
{
    use TagValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Scramble calls this method with no user when it builds the API
     * documentation. The fallback client id keeps that call working.
     *
     * @return array<string, array<int, Unique|string>>
     */
    public function rules(): array
    {
        /** @var Tag|null $tag */
        $tag = $this->route('tag');

        return $this->tagRules($this->user()->client_id ?? 0, $tag);
    }
}
