<?php

namespace App\Http\Requests\Api;

use App\Models\WebhookEndpoint;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWebhookEndpointRequest extends FormRequest
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
     * URL and events are immutable in v1 — recreate the endpoint to change them.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'active' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The validated fields for `UpdateWebhookEndpoint`, with `active` as a bool.
     *
     * @return array{description?: string|null, active?: bool}
     */
    public function attributesToUpdate(): array
    {
        $attributes = $this->safe()->only(['description']);

        if ($this->safe()->has('active')) {
            $attributes['active'] = $this->safe()->boolean('active');
        }

        return $attributes;
    }
}
