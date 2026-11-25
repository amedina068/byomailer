<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesEmailTemplate;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailTemplateRequest extends FormRequest
{
    use ValidatesEmailTemplate;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->emailTemplateRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->enforceUnsubscribeGuard($v));
    }
}
