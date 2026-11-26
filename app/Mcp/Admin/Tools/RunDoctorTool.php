<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\InstanceDoctor;
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

#[Name('run-doctor')]
#[Title('Run doctor')]
#[Description('Runs the health checks of php artisan byomailer:doctor, with the Amazon SES checks (AWS keys, region, send quota, verified identity, SQS event queue). Each check has an id, a status (ok, warn or fail), a message and a fix. This tool runs no fix. To run the safe fixes, run php artisan byomailer:doctor --fix on the server.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class RunDoctorTool extends AdminTool
{
    public function handle(Request $request, InstanceDoctor $doctor): Response
    {
        return $this->audited($request, function () use ($doctor): Response {
            $checks = $doctor->run(fix: false);

            return self::ok(['checks' => $checks, 'summary' => InstanceDoctor::summary($checks)]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
