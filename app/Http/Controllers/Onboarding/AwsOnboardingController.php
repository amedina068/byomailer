<?php

namespace App\Http\Controllers\Onboarding;

use App\Actions\SaveSesCredential;
use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateAwsCredentialsRequest;
use App\Services\AwsCredentialValidator;
use App\Services\SesClientManager;
use App\Services\SesTenantService;
use App\Services\SnsTopicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AwsOnboardingController extends Controller
{
    public function __construct(
        private AwsCredentialValidator $credentialValidator,
        private SnsTopicService $snsTopicService,
        private SesClientManager $sesClientManager,
        private SesTenantService $tenantService,
        private SaveSesCredential $saveSesCredential,
    ) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $hasCredentials = $user->sesCredential()->exists();

        return Inertia::render('onboarding/AwsCredentials', [
            'hasCredentials' => $hasCredentials,
        ]);
    }

    public function storeCredentials(ValidateAwsCredentialsRequest $request): JsonResponse
    {
        $user = $request->user();
        $accessKey = $request->validated('access_key');
        $secretKey = $request->validated('secret_key');
        $region = $request->validated('region');

        $validationResult = $this->credentialValidator->validate($accessKey, $secretKey, $region);

        if (! $validationResult['valid']) {
            return response()->json([
                'success' => false,
                'error' => $validationResult['error'],
            ], 422);
        }

        $topicName = Str::slug('mailautomation-'.$user->id.'-'.Str::uuid()->toString().'-events', '-');

        $topicResult = $this->snsTopicService->createTopic($topicName, $accessKey, $secretKey, $region);

        if (! $topicResult['success']) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create SNS topic: '.$topicResult['error'],
            ], 422);
        }

        $topicArn = $topicResult['topic_arn'];

        $sqsQueueArn = config('services.sqs_ses_events.queue_arn');

        if (! $sqsQueueArn) {
            $this->snsTopicService->deleteTopic($topicArn, $accessKey, $secretKey, $region);

            return response()->json([
                'success' => false,
                'error' => 'SQS queue ARN is not configured. Set SES_EVENTS_SQS_QUEUE_ARN in your environment.',
            ], 422);
        }

        $subscriptionResult = $this->snsTopicService->subscribeSqsEndpoint(
            $topicArn,
            $sqsQueueArn,
            $accessKey,
            $secretKey,
            $region
        );

        if (! $subscriptionResult['success']) {
            $this->snsTopicService->deleteTopic($topicArn, $accessKey, $secretKey, $region);

            return response()->json([
                'success' => false,
                'error' => 'Failed to subscribe SNS topic: '.$subscriptionResult['error'],
            ], 422);
        }

        $tenantResult = $this->tenantService->provisionTenant('byomailer', $accessKey, $secretKey, $region);

        if (! $tenantResult['success']) {
            $this->snsTopicService->deleteTopic($topicArn, $accessKey, $secretKey, $region);

            return response()->json([
                'success' => false,
                'error' => 'Failed to provision SES tenant: '.($tenantResult['error'] ?? 'Unknown error'),
            ], 422);
        }

        $credential = $this->saveSesCredential->handle($user, [
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'region' => $region,
            'tenant_name' => 'byomailer',
            'aws_account_id' => $validationResult['account_id'],
            'sns_topic_arn' => $topicArn,
            'sns_subscription_arn' => $subscriptionResult['subscription_arn'] ?? null,
            'sns_subscription_confirmed_at' => now(),
        ]);

        try {
            $this->sesClientManager->fetchAndUpdateQuota($user);
        } catch (Throwable) {
        }

        return response()->json([
            'success' => true,
            'credential_id' => $credential->id,
        ]);
    }
}
