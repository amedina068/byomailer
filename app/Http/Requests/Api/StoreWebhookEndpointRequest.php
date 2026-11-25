<?php

namespace App\Http\Requests\Api;

use App\Enums\WebhookEvent;
use App\Models\WebhookEndpoint;
use App\Services\SsrfGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(WebhookEndpoint::ADMIN_ONLY_MESSAGE);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! app(SsrfGuard::class)->isSafe((string) $value)) {
                        $fail('The webhook URL must be a public https endpoint.');
                    }
                },
            ],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(WebhookEvent::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $count = WebhookEndpoint::withoutGlobalScopes()
                ->where('client_id', $this->user()?->client_id)
                ->count();

            if ($count >= (int) config('webhooks.max_per_client')) {
                $validator->errors()->add('endpoints', 'You have reached the maximum number of webhook endpoints.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.required' => 'Select at least one event type.',
            'events.min' => 'Select at least one event type.',
        ];
    }
}
