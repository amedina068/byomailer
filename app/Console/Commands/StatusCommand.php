<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Support\Instance\InstanceStatus;
use Illuminate\Console\Command;

/**
 * Shows the state of this instance in one fast read. It exits 1 only when the
 * database does not answer. For deeper checks, run `byomailer:doctor`.
 */
class StatusCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'byomailer:status
        {--json : Write one JSON object to stdout}';

    protected $description = 'Show the version, database, queue, scheduler, SES and MCP state of this instance';

    public function handle(InstanceStatus $status): int
    {
        $data = $status->snapshot();
        $connected = $data['database']['connected'];
        $errors = $connected ? [] : [self::errorItem(
            'db_unreachable',
            "The {$data['database']['driver']} database does not answer: {$data['database']['error']}",
            'Make sure that the DB_* variables are correct and that the database server runs. Then run php artisan byomailer:doctor.',
        )];

        if (! $this->wantsJson()) {
            $this->render($data);
        }

        return $this->respond($connected ? self::EXIT_OK : self::EXIT_FAILED, $data, $errors);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): void
    {
        $yes = fn (bool $value): string => $value ? '<fg=green>yes</>' : '<fg=yellow>no</>';
        $ses = $data['ses'];

        $this->components->twoColumnDetail('Version', $data['version']);
        $this->components->twoColumnDetail('App URL', $data['app_url']);
        $this->components->twoColumnDetail('Database', $data['database']['driver'].' '.($data['database']['connected'] ? '<fg=green>connected</>' : '<fg=red>not connected</>'));
        $this->components->twoColumnDetail('Pending migrations', (string) ($data['database']['pending_migrations'] ?? 'unknown'));
        $this->components->twoColumnDetail('Queue', "{$data['queue']['connection']} ({$data['queue']['worker']}), last beat ".($data['queue']['heartbeat_at'] ?? 'never'));
        $this->components->twoColumnDetail('Scheduler', 'last run '.($data['scheduler']['last_run_at'] ?? 'never'));
        $this->components->twoColumnDetail('Storage link', $yes($data['storage_link']));
        $this->components->twoColumnDetail('App mailer', $data['mail_driver']);
        $this->components->twoColumnDetail('SES', $ses === null ? 'unknown' : "{$ses['accounts_with_credentials']} of {$ses['accounts']} accounts have AWS keys, {$ses['verified_identities']} verified identities");
        $this->components->twoColumnDetail('SES events (SQS)', $ses === null ? 'unknown' : $yes($ses['events_configured']));
        $this->components->twoColumnDetail('MCP', $data['mcp']['url']);
        $this->components->twoColumnDetail('Owner exists', $yes($data['owner_exists']));

        if ($data['setup_url'] !== null) {
            $this->components->twoColumnDetail('Setup URL', $data['setup_url']);
        }
    }
}
