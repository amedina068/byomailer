<?php

namespace App\Actions;

use App\Exceptions\SesSetupException;
use App\Models\EmailCredential;
use App\Models\User;
use App\Services\SesClientManager;
use App\Services\SesTenantService;
use App\Services\SnsTopicService;
use Aws\Credentials\Credentials;
use Illuminate\Support\Str;
use Throwable;

class ConnectSesAccount
{
    public function __construct(
        private SnsTopicService $snsTopicService,
        private SesTenantService $tenantService,
        private SaveSesCredential $saveSesCredential,
        private SesClientManager $sesClientManager,
    ) {}

    /**
     * Set up the AWS account of the client and save its SES credential.
     *
     * The action makes the SNS topic for the SES events, subscribes the topic
     * to the SQS queue, and makes the SES tenant. If a step fails, it removes
     * the topic again. The attributes are the access keys or the IAM role.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws SesSetupException
     */
    public function handle(User $user, Credentials $aws, string $region, string $accountId, array $attributes): EmailCredential
    {
        $sqsQueueArn = config('services.sqs_ses_events.queue_arn');
        $topicArn = $this->createTopic($user, $aws, $region);

        try {
            if (! $sqsQueueArn) {
                throw new SesSetupException('SQS queue ARN is not configured. Set SES_EVENTS_SQS_QUEUE_ARN in your environment.');
            }

            $subscription = $this->snsTopicService->subscribeSqsEndpoint(
                $topicArn, $sqsQueueArn, $aws->getAccessKeyId(), $aws->getSecretKey(), $region, $aws->getSecurityToken(),
            );

            if (! $subscription['success']) {
                throw new SesSetupException('Failed to subscribe SNS topic: '.$subscription['error']);
            }

            $tenant = $this->tenantService->provisionTenant(
                'byomailer', $aws->getAccessKeyId(), $aws->getSecretKey(), $region, $aws->getSecurityToken(),
            );

            if (! $tenant['success']) {
                throw new SesSetupException('Failed to provision SES tenant: '.($tenant['error'] ?? 'Unknown error'));
            }
        } catch (SesSetupException $exception) {
            $this->snsTopicService->deleteTopic($topicArn, $aws->getAccessKeyId(), $aws->getSecretKey(), $region, $aws->getSecurityToken());

            throw $exception;
        }

        $credential = $this->saveSesCredential->handle($user, [
            ...$attributes,
            'region' => $region,
            'tenant_name' => 'byomailer',
            'aws_account_id' => $accountId,
            'sns_topic_arn' => $topicArn,
            'sns_subscription_arn' => $subscription['subscription_arn'] ?? null,
            'sns_subscription_confirmed_at' => now(),
        ]);

        try {
            $user->unsetRelation('sesCredential');
            $this->sesClientManager->fetchAndUpdateQuota($user);
        } catch (Throwable) {
        }

        return $credential;
    }

    /**
     * @throws SesSetupException
     */
    private function createTopic(User $user, Credentials $aws, string $region): string
    {
        $topic = $this->snsTopicService->createTopic(
            Str::slug('mailautomation-'.$user->id.'-'.Str::uuid()->toString().'-events', '-'),
            $aws->getAccessKeyId(),
            $aws->getSecretKey(),
            $region,
            $aws->getSecurityToken(),
        );

        if (! $topic['success']) {
            throw new SesSetupException('Failed to create SNS topic: '.$topic['error']);
        }

        return $topic['topic_arn'];
    }
}
