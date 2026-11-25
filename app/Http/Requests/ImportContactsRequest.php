<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportContactsRequest extends FormRequest
{
    /**
     * The import runs in the web worker. The limit keeps one upload from
     * using all the memory or the CPU of the worker.
     */
    public const MAX_FILE_KILOBYTES = 20480;

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
            'list_id' => [
                'required',
                'integer',
                Rule::exists('lists', 'id')->where('client_id', $this->user()->client_id),
            ],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:'.self::MAX_FILE_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'list_id.required' => 'Please select a list to import contacts into.',
            'list_id.exists' => 'Selected list is invalid.',
            'file.required' => 'Please choose a file to import.',
            'file.mimes' => 'The import must be a CSV or Excel file.',
            'file.max' => 'The import file must not be larger than 20 MB.',
        ];
    }
}
