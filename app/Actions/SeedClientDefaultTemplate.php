<?php

namespace App\Actions;

use App\Models\Client;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Scopes\ClientScope;
use App\Support\DefaultTemplateContent;

class SeedClientDefaultTemplate
{
    /**
     * Clone the system default template into the given client's scope, marked as default.
     * Idempotent: if the client already has a default template, does nothing.
     */
    public function handle(Client $client, ?User $owner = null): ?EmailTemplate
    {
        $existingDefault = EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->where('client_id', $client->id)
            ->where('is_default', true)
            ->first();

        if ($existingDefault) {
            return $existingDefault;
        }

        $systemDefault = EmailTemplate::query()
            ->system()
            ->where('name', DefaultTemplateContent::NAME)
            ->first();

        $htmlContent = $systemDefault?->html_content ?? DefaultTemplateContent::html();
        $ownerId = $owner?->id ?? $client->users()->oldest('id')->value('id');

        $template = new EmailTemplate;
        $template->forceFill([
            'client_id' => $client->id,
            'user_id' => $ownerId,
            'name' => DefaultTemplateContent::NAME,
            'html_content' => $htmlContent,
            'is_default' => true,
        ])->save();

        return $template;
    }
}
