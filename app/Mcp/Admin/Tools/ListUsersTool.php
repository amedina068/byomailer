<?php

namespace App\Mcp\Admin\Tools;

use App\Console\Commands\UserListCommand;
use App\Mcp\Admin\AdminTool;
use App\Models\User;
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

#[Name('list-users')]
#[Title('List users')]
#[Description('Lists the users of every account of this instance: id, name, email, account, role, instance owner flag, deactivated flag, pending invitation flag and creation date. Give search to filter by name or email.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListUsersTool extends AdminTool
{
    public function handle(Request $request): Response
    {
        return $this->audited($request, function () use ($request): Response {
            $input = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            ]);

            $users = UserListCommand::search(trim((string) ($input['search'] ?? '')), (int) ($input['limit'] ?? 100));

            return self::ok(['users' => $users->map(fn (User $user): array => UserListCommand::describe($user))->values()->all()]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Show only the users whose name or email contains this text.'),
            'limit' => $schema->integer()->description('The maximum number of users (1 to 500, default 100).'),
        ];
    }
}
