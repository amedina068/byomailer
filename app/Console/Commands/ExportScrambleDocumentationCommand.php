<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Replaces the `scramble:export` command of Scramble.
 *
 * The command of Scramble writes the APP_URL host and no final newline, so
 * its file differs on each computer. This command runs `byomailer:openapi`,
 * which writes the same file each time.
 */
class ExportScrambleDocumentationCommand extends Command
{
    protected $signature = 'scramble:export
        {--path= : The path of the JSON file. With no path, the command writes the committed YAML and JSON files}
        {--api=default : The API to export. Only the default API is available}';

    protected $description = 'Write the OpenAPI document with byomailer:openapi';

    public function handle(): int
    {
        if ($this->option('api') !== 'default') {
            $this->components->error('Only the default API is available.');

            return self::FAILURE;
        }

        $options = $this->option('path') ? ['--json' => $this->option('path'), '--yaml' => ''] : [];

        return $this->call('byomailer:openapi', $options);
    }
}
