<?php

use App\Actions\SeedClientDefaultTemplate;
use App\Models\Client;
use App\Models\EmailTemplate;
use App\Scopes\ClientScope;
use App\Support\DefaultTemplateContent;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->updateOrCreate(
                [
                    'client_id' => null,
                    'user_id' => null,
                    'name' => DefaultTemplateContent::NAME,
                ],
                [
                    'html_content' => DefaultTemplateContent::html(),
                    'is_default' => false,
                ],
            );

        $action = new SeedClientDefaultTemplate;

        Client::query()->orderBy('id')->each(function (Client $client) use ($action): void {
            $action->handle($client);
        });
    }

    public function down(): void
    {
        EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->where('name', DefaultTemplateContent::NAME)
            ->where('is_default', true)
            ->delete();

        EmailTemplate::query()
            ->withoutGlobalScope(ClientScope::class)
            ->whereNull('client_id')
            ->whereNull('user_id')
            ->where('name', DefaultTemplateContent::NAME)
            ->delete();
    }
};
