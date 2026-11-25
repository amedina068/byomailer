<?php

namespace App\Console\Commands;

use App\Support\TenantOrphans;
use Illuminate\Console\Command;

/**
 * Reports the rows whose client_id points to no client. It changes nothing.
 * The client_id foreign key migration refuses to run while such rows exist.
 */
class TenancyOrphansCommand extends Command
{
    protected $signature = 'tenancy:orphans';

    protected $description = 'List the rows whose client_id has no client (read-only)';

    public function handle(): int
    {
        $orphans = TenantOrphans::counts(TenantOrphans::tables());

        if ($orphans === []) {
            $this->components->info('No orphan rows.');

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Orphan rows', 'Missing client ids'],
            collect($orphans)->map(fn (int $count, string $table): array => [
                $table,
                $count,
                implode(', ', TenantOrphans::missingClientIds($table)),
            ])->values(),
        );

        $this->components->warn('These rows belong to no client. Nothing was changed.');

        return self::FAILURE;
    }
}
