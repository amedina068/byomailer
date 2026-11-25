<?php

namespace App\Console\Commands;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SnsTopicService;
use Illuminate\Console\Command;
use Throwable;

class MigrateSnsToSqsCommand extends Command
{
    protected $signature = 'ses-events:migrate-to-sqs
        {--dry-run : Show what would be done without making changes}
        {--user-id= : Migrate only the client of this user ID}';

    protected $description = 'Migrate existing SNS HTTP subscriptions to SQS subscriptions';

    public function handle(SnsTopicService $snsTopicService): int
    {
        $sqsQueueArn = config('services.sqs_ses_events.queue_arn');

        if (! $sqsQueueArn) {
            $this->error('SES_EVENTS_SQS_QUEUE_ARN is not configured.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        $query = EmailCredential::withoutGlobalScopes()
            ->whereNotNull('sns_topic_arn')
            ->with('user');

        if ($userId = $this->option('user-id')) {
            $query->whereIn('client_id', User::query()->select('client_id')->whereKey($userId));
        }

        $credentials = $query->get();

        if ($credentials->isEmpty()) {
            $this->info('No credentials with SNS topics found.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Processing {$credentials->count()} credential(s)...");

        $success = 0;
        $failed = 0;

        foreach ($credentials as $credential) {
            $label = "Credential {$credential->id} (user {$credential->user_id})";

            if ($dryRun) {
                $this->line("  [DRY RUN] {$label}: Would subscribe SQS to {$credential->sns_topic_arn}");

                if ($credential->sns_subscription_arn && ! str_starts_with($credential->sns_subscription_arn, 'pending')) {
                    $this->line("  [DRY RUN] {$label}: Would unsubscribe {$credential->sns_subscription_arn}");
                }

                $success++;

                continue;
            }

            try {
                $subscribeResult = $snsTopicService->subscribeSqsEndpoint(
                    $credential->sns_topic_arn,
                    $sqsQueueArn,
                    $credential->access_key,
                    $credential->secret_key,
                    $credential->region
                );

                if (! $subscribeResult['success']) {
                    $this->error("  {$label}: Failed to subscribe SQS — {$subscribeResult['error']}");
                    $failed++;

                    continue;
                }

                $oldSubscriptionArn = $credential->sns_subscription_arn;

                $credential->forceFill([
                    'sns_subscription_arn' => $subscribeResult['subscription_arn'],
                    'sns_subscription_confirmed_at' => now(),
                ])->save();

                if ($oldSubscriptionArn && ! str_starts_with($oldSubscriptionArn, 'pending')) {
                    $unsubResult = $snsTopicService->unsubscribe(
                        $oldSubscriptionArn,
                        $credential->access_key,
                        $credential->secret_key,
                        $credential->region
                    );

                    if (! $unsubResult['success']) {
                        $this->warn("  {$label}: SQS subscribed but failed to unsubscribe old HTTPS — {$unsubResult['error']}");
                    }
                }

                $this->line("  {$label}: Migrated successfully");
                $success++;
            } catch (Throwable $e) {
                $this->error("  {$label}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] ' : '')."Done. Success: {$success}, Failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
