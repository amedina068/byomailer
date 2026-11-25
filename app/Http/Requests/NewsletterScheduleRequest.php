<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesProjectIds;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class NewsletterScheduleRequest extends FormRequest
{
    use ValidatesProjectIds;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $clientId = $this->user()->client_id;

        return [
            'action' => ['required', Rule::in(['draft', 'schedule', 'send_now'])],
            'scheduled_for' => ['nullable', 'string', 'date_format:Y-m-d\TH:i', 'required_if:action,schedule'],
            'email_identity_id' => [
                'required',
                Rule::exists('email_identities', 'id')->where(fn ($query) => $query->where('client_id', $clientId)),
            ],
            ...$this->projectIdRules(),
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('action') !== 'schedule') {
                return;
            }

            $value = $this->input('scheduled_for');

            if (! is_string($value) || trim($value) === '') {
                return;
            }

            $timezone = $this->user()->client->timezone;

            try {
                $scheduledFor = Carbon::createFromFormat('Y-m-d\TH:i', $value, $timezone);
            } catch (Throwable $exception) {
                return;
            }

            if ($scheduledFor->lessThanOrEqualTo(now($timezone))) {
                $validator->errors()->add('scheduled_for', 'The scheduled time must be in the future.');
            }
        });
    }
}
