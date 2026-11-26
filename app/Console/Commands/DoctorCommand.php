<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesJsonOutput;
use App\Support\Instance\DoctorCheck;
use App\Support\Instance\InstanceDoctor;
use Illuminate\Console\Command;

/**
 * Runs the health checks of `InstanceDoctor`. It exits 1 when a check fails.
 * A warning does not change the exit code. `--fix` runs only the safe fixes.
 */
class DoctorCommand extends Command
{
    use WritesJsonOutput;

    protected $signature = 'byomailer:doctor
        {--fix : Run the safe fixes (the storage link)}
        {--json : Write one JSON object to stdout}';

    protected $description = 'Check this instance and its Amazon SES setup, and show how to correct each problem';

    public function handle(InstanceDoctor $doctor): int
    {
        $checks = $doctor->run((bool) $this->option('fix'));
        $summary = InstanceDoctor::summary($checks);
        $failed = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === DoctorCheck::FAIL));

        if (! $this->wantsJson()) {
            $this->render($checks, $summary);
        }

        return $this->respond(
            $failed === [] ? self::EXIT_OK : self::EXIT_FAILED,
            ['checks' => $checks, 'summary' => $summary],
            array_map(fn (array $check): array => self::errorItem($check['id'], $check['message'], $check['fix']), $failed),
        );
    }

    /**
     * @param  list<array{id: string, status: string, message: string, fix: string}>  $checks
     * @param  array{ok: int, warn: int, fail: int}  $summary
     */
    private function render(array $checks, array $summary): void
    {
        foreach ($checks as $check) {
            $label = match ($check['status']) {
                DoctorCheck::OK => '<fg=green>OK</>',
                DoctorCheck::WARN => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            };

            $this->components->twoColumnDetail($check['id'].'  <fg=gray>'.$check['message'].'</>', $label);

            if ($check['fix'] !== '') {
                $this->line('    <fg=gray>Fix: '.$check['fix'].'</>');
            }
        }

        $this->newLine();
        $this->line(sprintf('  %d ok, %d warn, %d fail.', $summary['ok'], $summary['warn'], $summary['fail']));
    }
}
