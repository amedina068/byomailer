<?php

namespace App\Http\Requests;

use App\Models\UserStorageSetting;
use App\Services\SsrfGuard;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserStorageSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in([UserStorageSetting::PROVIDER_S3, UserStorageSetting::PROVIDER_R2])],
            'bucket' => ['required', 'string', 'max:255'],
            'region' => ['required', 'string', 'max:100'],
            'access_key_id' => ['required', 'string', 'max:255'],
            'secret_access_key' => ['required', 'string', 'max:512'],
            'public_base_url' => ['required', 'string', 'url', 'max:255', 'starts_with:https://'],
            'endpoint' => [
                Rule::requiredIf($this->input('provider') === UserStorageSetting::PROVIDER_R2),
                'nullable',
                'string',
                'url',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! app(SsrfGuard::class)->isSafe((string) $value)) {
                        $fail('The endpoint must be a public https URL.');
                    }
                },
            ],
            'path_prefix' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'public_base_url.starts_with' => 'The public base URL must start with https://.',
            'endpoint.required_if' => 'The endpoint is required for Cloudflare R2.',
        ];
    }

    /**
     * Normalize the public_base_url by removing trailing slashes.
     *
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('public_base_url')) {
            $this->merge([
                'public_base_url' => rtrim((string) $this->input('public_base_url'), '/'),
            ]);
        }
    }
}
