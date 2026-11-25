<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Lists the users of the instance, to find the email for
 * `byomailer:user:password`.
 */
class UserListCommand extends Command
{
    protected $signature = 'byomailer:user:list
        {--search= : Show only the users whose name or email contains this text}';

    protected $description = 'List the users of this instance';

    public function handle(): int
    {
        $search = trim((string) $this->option('search'));

        $users = User::query()
            ->with('client:id,name')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '\\%_').'%';

                $query->where(fn ($query) => $query
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like));
            })
            ->orderBy('id')
            ->get();

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
}
