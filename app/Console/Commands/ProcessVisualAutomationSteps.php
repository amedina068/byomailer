<?php

namespace App\Console\Commands;

use App\Jobs\ProcessVisualAutomationEnrollmentJob;
use App\Services\VisualAutomationEngine;
use Illuminate\Console\Command;

class ProcessVisualAutomationSteps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visual-automations:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch jobs to process pending visual automation steps';

    /**
     * Execute the console command.
     */
    public function handle(VisualAutomationEngine $engine): int
    {
        $count = 0;

        foreach ($engine->findProcessableEnrollmentIds() as $enrollmentId) {
            ProcessVisualAutomationEnrollmentJob::dispatch($enrollmentId)
                ->onQueue('automations');
            $count++;
        }

        $this->info("Dispatched {$count} visual automation enrollment jobs.");

        return self::SUCCESS;
    }
}
