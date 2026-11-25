<?php

namespace App\Http\Requests;

use App\Enums\DelayUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSequenceStepRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientId = $this->user()?->client_id ?? 0;

        return [
            'delay_type' => ['required', Rule::enum(DelayUnit::class)],
            'delay_days' => ['required', 'integer', 'min:0', 'max:'.DelayUnit::maxDelayFor($this->input('delay_type'))],
            'email_identity_id' => [
                'required',
                'integer',
                Rule::exists('email_identities', 'id')->where(fn ($query) => $query->where('client_id', $clientId)),
            ],
        ];
    }
}
