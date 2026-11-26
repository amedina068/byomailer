<?php

use App\Http\Middleware\EnsureInstanceAdminAccess;
use App\Mcp\Servers\ByoMailerServer;
use App\Mcp\Servers\InstanceAdminServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', ByoMailerServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp']);

/*
|--------------------------------------------------------------------------
| Instance-Admin MCP Server
|--------------------------------------------------------------------------
|
| The operator server runs over stdio. An agent starts it on the server:
|
|   ssh host docker exec -i <container> php artisan mcp:start byomailer-admin
|
| The HTTP route exists only when MCP_ADMIN_ENABLED=true. It accepts only an
| owner token with the `instance_admin` ability that expires in 7 days or less.
|
*/
Mcp::local(InstanceAdminServer::HANDLE, InstanceAdminServer::class);

if (config('mcp.admin_enabled')) {
    Mcp::web('/mcp/admin', InstanceAdminServer::class)
        ->middleware(['auth:sanctum', EnsureInstanceAdminAccess::class, 'throttle:mcp']);
}
