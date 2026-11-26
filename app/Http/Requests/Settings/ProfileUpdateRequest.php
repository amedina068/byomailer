<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            ...$this->accountSettingsRules(),
        ];
    }

    /**
     * The timezone, the sunset policy and the postal address belong to the
     * client, not to the user. A user without write access (a Viewer) can
     * still save the own name and email, but cannot change these values.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user instanceof User || $user->hasWriteAccess() || ! $user->client instanceof Client) {
                    return;
                }

                foreach ($this->changedClientSettings($user->client) as $field) {
                    $validator->errors()->add($field, 'Only an Admin or an Editor can change this account setting.');
                }
            },
        ];
    }

    /**
     * @return list<string>
     */
    private function changedClientSettings(Client $client): array
    {
        $changed = [];

        if ((string) $this->input('timezone') !== (string) $client->timezone) {
            $changed[] = 'timezone';
        }

        foreach (['sunset_inactive_days', 'postal_address'] as $field) {
            if ($this->has($field) && trim((string) $this->input($field)) !== trim((string) $client->{$field})) {
                $changed[] = $field;
            }
        }

        return $changed;
    }
}
