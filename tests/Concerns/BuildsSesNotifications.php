<?php

namespace Tests\Concerns;

use App\Models\ContactMail;
use App\Models\EmailCredential;
use App\Models\User;
use Illuminate\Support\Str;

trait BuildsSesNotifications
{
    /**
     * Wrap SES events in the SNS envelope that the topic of the tenant sends.
     * The tenant is the client of the contact mail that the first event names.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, string>
     */
    protected function sesNotification(array $events): array
    {
        $uuid = data_get($events, '0.mail.tags.contact_mail_uuid.0');
        $contactMail = $uuid
            ? ContactMail::withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail()
            : ContactMail::withoutGlobalScopes()->findOrFail(data_get($events, '0.mail.tags.contact_mail_id.0'));

        return [
            'Type' => 'Notification',
            'MessageId' => (string) Str::uuid(),
            'TopicArn' => $this->sesTopicArnFor($contactMail->client_id),
            'Message' => json_encode(['events' => $events]),
        ];
    }

    /**
     * The SNS topic of the client. It creates the credential when the client has none.
     */
    protected function sesTopicArnFor(int $clientId): string
    {
        $credential = EmailCredential::withoutGlobalScopes()->where('client_id', $clientId)->first()
            ?? EmailCredential::factory()->create([
                'user_id' => User::query()->where('client_id', $clientId)->value('id')
                    ?? User::factory()->create(['client_id' => $clientId])->id,
                'client_id' => $clientId,
            ]);

        if (! $credential->sns_topic_arn) {
            $credential->forceFill(['sns_topic_arn' => "arn:aws:sns:us-east-1:123456789012:mailautomation-{$clientId}-events"])->save();
        }

        return $credential->sns_topic_arn;
    }
}
