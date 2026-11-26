<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\InstanceStatus;
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

#[Name('get-instance-status')]
#[Title('Get instance status')]
#[Description('Returns the version, app URL, database state, pending migrations, queue and scheduler heartbeats, storage link, app mailer, MCP state, a summary of the SES state and whether an owner exists. The same data as php artisan byomailer:status --json.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetInstanceStatusTool extends AdminTool
{
    public function handle(Request $request, InstanceStatus $status): Response
    {
        return $this->audited($request, fn (): Response => self::ok($status->snapshot()));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
