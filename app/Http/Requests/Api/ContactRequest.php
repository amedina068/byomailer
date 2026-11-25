<?php

namespace App\Http\Requests\Api;

use App\Models\BlacklistedEmail;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $contact = $this->contactFromRoute();

        if (! $contact && $this->isMethod('post') && $this->filled('email')) {
            $contact = Contact::query()
                ->where('email', $this->input('email'))
                ->first();
        }

        $contactId = $contact?->id;

        $emailRules = [
            $this->isMethod('post') ? 'required' : 'sometimes',
            'email',
            Rule::unique('contacts', 'email')
                ->ignore($contactId)
                ->where(fn ($query) => $query->where('client_id', $this->user()->client_id)),
        ];

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => $emailRules,
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:255'],
            'remove_tags' => ['sometimes', 'array'],
            'remove_tags.*' => ['string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateTagConflicts($validator);

            if (! $this->shouldValidateBlacklist()) {
                return;
            }

            $email = $this->input('email');

            if ($email && $this->isEmailBlacklisted($email)) {
                $validator->errors()->add('email', __('This email address has been blacklisted.'));
            }
        });
    }

    /**
     * A tag name in tags and in remove_tags makes the request ambiguous.
     * The comparison ignores case and surrounding spaces.
     */
    private function validateTagConflicts(Validator $validator): void
    {
        $tags = $this->input('tags');
        $removeTags = $this->input('remove_tags');

        if (! is_array($tags) || ! is_array($removeTags)) {
            return;
        }

        $normalize = fn (array $names): array => array_map(
            fn (mixed $name): string => mb_strtolower(trim((string) $name)),
            array_filter($names, 'is_string'),
        );

        $conflicts = array_values(array_unique(array_intersect($normalize($tags), $normalize($removeTags))));

        if ($conflicts !== []) {
            $validator->errors()->add(
                'remove_tags',
                __('These tags are in tags and in remove_tags: :tags.', ['tags' => implode(', ', $conflicts)]),
            );
        }
    }

    private function shouldValidateBlacklist(): bool
    {
        if (! $this->user()) {
            return false;
        }

        if (! $this->has('email')) {
            return false;
        }

        if ($this->isMethod('post')) {
            return ! $this->hasExistingContactForEmail();
        }

        $contact = $this->contactFromRoute();

        if (! $contact) {
            return true;
        }

        return $this->input('email') !== $contact->email;
    }

    private function isEmailBlacklisted(string $email): bool
    {
        $clientId = $this->user()->client_id;

        return BlacklistedEmail::withoutGlobalScopes()
            ->where('email', Str::lower($email))
            ->where(function ($query) use ($clientId): void {
                $query->whereNull('client_id')
                    ->orWhere('client_id', $clientId);
            })
            ->exists();
    }

    private function hasExistingContactForEmail(): bool
    {
        if (! $this->user() || ! $this->filled('email')) {
            return false;
        }

        return Contact::query()
            ->where('email', $this->input('email'))
            ->exists();
    }

    /**
     * A create request has no contact in the route, so it needs no query.
     * Scramble calls rules() with no route and no tenant to document the request.
     */
    private function contactFromRoute(): ?Contact
    {
        $contactId = $this->route('contactId');

        if ($contactId === null) {
            return null;
        }

        return Contact::query()->find($contactId);
    }
}
