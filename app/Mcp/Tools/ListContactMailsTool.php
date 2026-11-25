<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ListsContactMails;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List contact emails for the authenticated account with contact, newsletter, sequence, step, status, engagement, search and sent-date filters, sorting and pagination. Returns recipients, mail ids and delivery/engagement timestamps. Use contact-mail-details-tool to inspect a mail body and clicks.')]
class ListContactMailsTool extends Tool
{
    use ListsContactMails;

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->mailListResponse($request, $this->validateMailFilters($request));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return $this->mailSchema($schema);
    }
}
