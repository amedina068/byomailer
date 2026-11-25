<?php

namespace App\Http\Requests;

use App\Services\ContactMailQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class ExportSequenceStepContactMailsRequest extends FormRequest
{
    /**
     * The route names the step, so the export takes no mailable filter. It
     * writes every matching row, so it takes no page either.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Arr::except(ContactMailQuery::rules(), ['newsletter_id', 'sequence_id', 'step_id', 'page', 'per_page']),
            /**
             * @deprecated Ignored. The export always writes the full history of the step.
             */
            'current_only' => ['sometimes', 'boolean'],
        ];
    }
}
