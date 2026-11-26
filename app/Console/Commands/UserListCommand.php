<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Models\Client;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lists the users of the instance, to find the email for
 * `byomailer:user:password`.
 */
class UserListCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'byomailer:user:list
        {--search= : Show only the users whose name or email contains this text}
        {--json : Write one JSON object to stdout}';

    protected $description = 'List the users of this instance';

    public function handle(): int
    {
        $users = self::search(trim((string) $this->option('search')));

        if ($this->wantsJson()) {
            return $this->respond(self::EXIT_OK, [
                'users' => $users->map(fn (User $user): array => self::describe($user))->values()->all(),
            ]);
        }

        if ($users->isEmpty()) {
            $this->components->info('No user matches.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Email', 'Account', 'Owner', 'Status', 'Created'],
            $users->map(fn (User $user): array => [
                $user->id,
                $user->name,
                $user->email,
                $user->client?->name,
                $user->isOwner() ? 'yes' : 'no',
                $user->isDeactivated() ? 'deactivated' : 'active',
                $user->created_at?->toDateString(),
            ]),
        );

        return self::SUCCESS;
    }

    /**
     * The users whose name or email contains the text, oldest first.
     *
     * @return Collection<int, User>
     */
    public static function search(string $search, ?int $limit = null): Collection
    {
        return User::query()
            ->with('client:id,name')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $query) => $query->limit($limit))
            ->get();
    }

    /**
     * The user fields that the CLI and the instance-admin MCP server show.
     *
     * @return array<string, mixed>
     */
    public static function describe(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account' => ($client = $user->client) instanceof Client
                ? ['id' => $client->id, 'name' => $client->name]
                : null,
            'role' => $user->role->value,
            'is_owner' => $user->isOwner(),
            'deactivated' => $user->isDeactivated(),
            'invited' => $user->invite_expires_at !== null,
            'email_verified' => $user->email_verified_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
