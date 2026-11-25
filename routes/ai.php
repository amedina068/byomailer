<?php

use App\Mcp\Servers\ByoMailerServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', ByoMailerServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp']);
