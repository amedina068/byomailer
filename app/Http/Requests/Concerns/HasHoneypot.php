<?php

namespace App\Http\Requests\Concerns;

trait HasHoneypot
{
    /**
     * @return array<string, array<int, string>>
     */
    public function honeypotRules(): array
    {
        return [
            'phone' => ['nullable', 'string'],
        ];
    }

    /**
     * Check if the submission passes the honeypot checks.
     */
    public function passesHoneypot(): bool
    {
        return ! $this->filled('phone');
    }
}
