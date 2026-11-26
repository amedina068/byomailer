<?php

/*
| The `laravel/mcp` package merges its own defaults (`redirect_domains`,
| `custom_schemes`, `authorization_server`) into this file.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Instance-admin MCP server over HTTP
    |--------------------------------------------------------------------------
    |
    | The instance-admin MCP server runs over stdio by default:
    | `php artisan mcp:start byomailer-admin`. When this is true, the app also
    | serves it at `/mcp/admin`. That route accepts only an owner with a token
    | that carries the `instance_admin` ability and expires in 7 days or less.
    |
    */

    'admin_enabled' => (bool) env('MCP_ADMIN_ENABLED', false),

];
