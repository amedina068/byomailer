<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Scopes\ClientScope;
use App\Support\DefaultTemplateContent;
use Illuminate\Database\Seeder;

class DefaultEmailTemplateSeeder extends Seeder
{
    public function run(): void
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
    }
}
