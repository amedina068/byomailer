<?php

namespace App\Http\Requests;

use App\Models\VisualAutomation;
use App\Rules\SequenceMustBeLive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVisualAutomationStatusRequest extends FormRequest
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
        return [
            'status' => ['required', Rule::in([
                VisualAutomation::STATUS_DRAFT,
                VisualAutomation::STATUS_LIVE,
            ])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('status') !== VisualAutomation::STATUS_LIVE) {
                return;
            }

            $automation = VisualAutomation::query()
                ->find($this->route('visualAutomation'));

            if (! $automation) {
                return;
            }

            $sequenceIds = [];
            foreach ($automation->workflow['nodes'] ?? [] as $node) {
                $data = $node['data'] ?? [];
                if (($data['action_type'] ?? null) === 'add_sequence' && isset($data['value'])) {
                    $sequenceIds[] = $data['value'];
                }
            }

            if (! SequenceMustBeLive::allLive($sequenceIds)) {
                $validator->errors()->add('status', SequenceMustBeLive::MESSAGE);
            }
        });
    }
}
