<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Registration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-settings')]
#[Title('Get settings')]
#[Description('Returns the settings that set-setting can change, with the allowed values, and the read-only settings that come from the environment.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetSettingsTool extends AdminTool
{
    public function handle(Request $request): Response
    {
        return $this->audited($request, fn (): Response => self::ok([
            'settings' => [
                Registration::SETTING => ['value' => Registration::mode(), 'allowed' => SetSettingTool::ALLOWED[Registration::SETTING]],
            ],
            'read_only' => [
                'app_url' => (string) config('app.url'),
                'app_mailer' => (string) config('mail.default'),
                'mail_through_ses_api' => (bool) config('mail.use_ses_api'),
                'queue_connection' => (string) config('queue.default'),
                'cache_store' => (string) config('cache.default'),
                'ses_events_region' => config('services.sqs_ses_events.region'),
                'mcp_admin_http_enabled' => (bool) config('mcp.admin_enabled'),
            ],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
