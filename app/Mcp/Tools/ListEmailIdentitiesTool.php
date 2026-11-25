<?php

namespace App\Mcp\Tools;

use App\Enums\IdentityStatus;
use App\Mcp\Concerns\FormatsEmailIdentity;
use App\Models\EmailIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the sender identities (verified sending domains) of the authenticated account, returning each id, email, name, domain, verification status, DKIM and MAIL FROM status and tracking flags. Use the returned id with email-identity-details-tool, refresh-email-identity-status-tool or update-email-identity-tracking-tool. Only identities with identity_status "verified" can send mail.')]
class ListEmailIdentitiesTool extends Tool
{
    use FormatsEmailIdentity;

    public function handle(Request $request): Response|ResponseFactory
    {
        $statuses = array_map(fn (IdentityStatus $status): string => $status->value, IdentityStatus::cases());

        $validated = $request->validate([
            'identity_status' => ['sometimes', 'string', 'in:'.implode(',', $statuses)],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));

        $identities = EmailIdentity::query()
            ->with('dnsRecords')
            ->when(isset($validated['identity_status']), fn ($query) => $query->where('identity_status', $validated['identity_status']))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get()
            ->map(fn (EmailIdentity $identity): array => $this->identitySummary($identity))
            ->all();

        return Response::structured([
            'identities' => $identities,
            'count' => count($identities),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'identity_status' => $schema->string()
                ->enum(array_map(fn (IdentityStatus $status): string => $status->value, IdentityStatus::cases()))
                ->description('Optional verification status filter.'),
            'search' => $schema->string()
                ->description('Optional case-insensitive search against the identity email, name and domain.'),
            'limit' => $schema->integer()
                ->description('Maximum number of identities to return (1-100, default 25).'),
        ];
    }
}
