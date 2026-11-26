<?php

namespace App\Mcp\Admin\Tools;

use App\Enums\UserRole;
use App\Mcp\Admin\AdminTool;
use App\Models\Client;
use App\Models\User;
use App\Support\Instance\SesStatus;
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

#[Name('list-accounts')]
#[Title('List accounts')]
#[Description('Lists the accounts of this instance. Each account has its own users, contacts, campaigns and Amazon SES keys. For each account the tool returns the id, the name, the number of users, the emails of the team admins, a short SES summary (AWS keys set, region, production access, verified identities) and the creation date. Call get-ses-status for the full SES state of one account.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListAccountsTool extends AdminTool
{
    /** The response lists at most this many admin emails for each account. */
    private const ADMINS_SHOWN = 5;

    public function handle(Request $request, SesStatus $ses): Response
    {
        return $this->audited($request, function () use ($request, $ses): Response {
            $input = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:500']]);
            $limit = (int) ($input['limit'] ?? 100);

            $clients = Client::query()->withCount('users')->orderBy('id')->limit($limit)->get(['id', 'name', 'created_at']);
            $sesByClient = collect($ses->accounts(null, $limit))->keyBy('id');
            $admins = User::query()
                ->whereIn('client_id', $clients->pluck('id'))
                ->where('role', UserRole::Admin->value)
                ->orderBy('id')
                ->get(['client_id', 'email'])
                ->groupBy('client_id');

            return self::ok(['accounts' => $clients->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'users_count' => $client->users_count,
                'admins' => $admins->get($client->id, collect())->take(self::ADMINS_SHOWN)->pluck('email')->all(),
                'ses' => $this->sesSummary($sesByClient->get($client->id)),
                'created_at' => $client->created_at?->toIso8601String(),
            ])->values()->all()]);
        });
    }

    /**
     * @param  array<string, mixed>|null  $account
     * @return array{credentials: bool, region: string|null, production_access: bool|null, verified_identities: int}
     */
    private function sesSummary(?array $account): array
    {
        return [
            'credentials' => (bool) ($account['credentials'] ?? false),
            'region' => $account['region'] ?? null,
            'production_access' => $account['quota']['production_access'] ?? null,
            'verified_identities' => (int) ($account['identities']['verified'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('The maximum number of accounts (1 to 500, default 100).'),
        ];
    }
}
