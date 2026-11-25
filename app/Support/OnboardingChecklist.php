<?php

namespace App\Support;

use App\Enums\NewsletterStatus;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

class OnboardingChecklist
{
    /**
     * @return array{steps: array<int, array{key: string, label: string, completed: bool, href: string}>, completed: int, total: int}
     */
    public static function forClient(Client $client): array
    {
        $checks = DB::query()
            ->selectRaw('EXISTS(SELECT 1 FROM email_credentials WHERE client_id = ? AND type = ?) as has_ses', [$client->id, 'ses'])
            ->selectRaw('EXISTS(SELECT 1 FROM email_identities WHERE client_id = ?) as has_identity', [$client->id])
            ->selectRaw('EXISTS(SELECT 1 FROM email_identities WHERE client_id = ? AND custom_tracking_domain_verified_at IS NOT NULL) as has_tracking', [$client->id])
            ->selectRaw('EXISTS(SELECT 1 FROM user_storage_settings WHERE client_id = ?) as has_storage', [$client->id])
            ->selectRaw('EXISTS(SELECT 1 FROM contacts WHERE client_id = ?) as has_contacts', [$client->id])
            ->selectRaw('EXISTS(SELECT 1 FROM newsletters WHERE client_id = ? AND status = ?) as has_sent_newsletter', [$client->id, NewsletterStatus::Sent->value])
            ->first();

        $steps = [
            [
                'key' => 'aws_credentials',
                'label' => 'Add AWS credentials',
                'completed' => (bool) $checks->has_ses,
                'href' => '/onboarding/aws',
            ],
            [
                'key' => 'email_identity',
                'label' => 'Create email identity',
                'completed' => (bool) $checks->has_identity,
                'href' => '/settings/identities',
            ],
            [
                'key' => 'domain_tracking',
                'label' => 'Set up domain tracking',
                'completed' => (bool) $checks->has_tracking,
                'href' => '/settings/identities',
            ],
            [
                'key' => 'storage',
                'label' => 'Add storage',
                'completed' => (bool) $checks->has_storage,
                'href' => '/settings/storage',
            ],
            [
                'key' => 'import_contacts',
                'label' => 'Import contacts',
                'completed' => (bool) $checks->has_contacts,
                'href' => '/contacts/import',
            ],
            [
                'key' => 'send_newsletter',
                'label' => 'Send a newsletter',
                'completed' => (bool) $checks->has_sent_newsletter,
                'href' => '/newsletters/create',
            ],
        ];

        $completed = collect($steps)->where('completed', true)->count();

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
        ];
    }
}
