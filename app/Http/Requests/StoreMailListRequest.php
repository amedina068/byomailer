<?php

namespace App\Http\Requests;

use App\Concerns\MailListValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Unique;

class StoreMailListRequest extends FormRequest
{
    use MailListValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, Unique|string>>
     */
    public function rules(): array
    {
        return $this->mailListRules($this->user()->client_id);
    }
}
