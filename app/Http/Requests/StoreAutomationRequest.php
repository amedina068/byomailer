<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectIds;
use App\Models\Automation;
use App\Support\AutomationDefinition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAutomationRequest extends FormRequest
{
    use ValidatesProjectIds;

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
        return [
            ...AutomationDefinition::rules($this->user(), $this->all()),
            ...$this->projectIdRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AutomationDefinition::messages($this->all());
    }

    /**
     * Get the validated data with a condition that holds only the keys of its type.
     *
     * @return array<string, mixed>
     */
    public function automationData(): array
    {
        return AutomationDefinition::normalize($this->validated());
    }

    /**
     * A new automation starts live. An edit keeps the live flag of the
     * automation, so a paused automation can point to a draft sequence.
     */
    private function automationIsLive(): bool
    {
        $automation = $this->route('automation');

        return ! $automation instanceof Automation || $automation->is_live;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(AutomationDefinition::prepare($this->only('condition')));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            AutomationDefinition::validateReferences($validator, $this->user(), $this->all(), $this->automationIsLive());
        });
    }
}
