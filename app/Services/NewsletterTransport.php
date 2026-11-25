<?php

namespace App\Services;

use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class NewsletterTransport
{
    private const DEFAULT_CHUNK_SIZE = 50;

    public function __construct(
        private SesBulkEmailService $bulkEmailService,
        private SesClientManager $clientManager,
        private UserMailerFactory $userMailerFactory,
    ) {}

    public function createTemplate(Newsletter $newsletter, User $owner, Message $message): ?string
    {
        if ($message->currentTemplate()) {
            $message->forceFill([
                'html_content' => $message->composedHtml(),
                'text_content' => $message->composedText(),
            ])->save();
        }

        if (! $this->usesSesApi()) {
            return null;
        }

        return $this->bulkEmailService->createNewsletterTemplate($newsletter, $owner, $message);
    }

    public function deleteTemplate(?string $templateName, User $owner): void
    {
        if (! $this->usesSesApi() || $templateName === null) {
            return;
        }

        $this->bulkEmailService->deleteTemplate($templateName, $owner);
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @param  Collection<int, ContactMail>  $contactMails
     * @param  array<string, string>  $defaultTemplateData
     */
    public function sendBatch(
        ?string $templateName,
        string $fromEmail,
        ?string $fromName,
        Collection $contacts,
        Collection $contactMails,
        User $user,
        array $defaultTemplateData,
        ?string $tenantName,
        Message $message,
    ): void {
        if ($this->usesSesApi()) {
            $this->sendViaSes($templateName, $fromEmail, $fromName, $contacts, $contactMails, $user, $defaultTemplateData, $tenantName, $message);
        } else {
            $this->sendViaMailer($contacts, $contactMails, $user);
        }
    }

    public function getChunkSize(User $user): int
    {
        if (! $this->usesSesApi()) {
            return self::DEFAULT_CHUNK_SIZE;
        }

        $maxSendRate = max(1, $this->clientManager->getMaxSendRateFor($user));
        $jobsPerSecond = max(1, (int) ceil($maxSendRate / self::DEFAULT_CHUNK_SIZE));

        return max(1, (int) floor($maxSendRate / $jobsPerSecond));
    }

    public function usesSesApi(): bool
    {
        return (bool) config('mail.use_ses_api');
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @param  Collection<int, ContactMail>  $contactMails
     * @param  array<string, string>  $defaultTemplateData
     */
    private function sendViaSes(
        ?string $templateName,
        string $fromEmail,
        ?string $fromName,
        Collection $contacts,
        Collection $contactMails,
        User $user,
        array $defaultTemplateData,
        ?string $tenantName,
        Message $message,
    ): void {
        $message->loadMissing('emailIdentity');

        $destinations = $contacts->map(function (Contact $contact) use ($contactMails, $message) {
            $contactMail = $contactMails->firstWhere('contact_id', $contact->id);
            $contactMail->setRelation('message', $message);

            return [
                'email' => $contact->email,
                'name' => $contact->name,
                'data' => $contactMail->template_data ?? [],
                'tags' => [
                    ['Name' => 'contact_mail_uuid', 'Value' => (string) $contactMail->uuid],
                ],
                'unsubscribe_url' => $contactMail->unsubscribe_link,
                'one_click_unsubscribe_url' => $contactMail->one_click_unsubscribe_link,
            ];
        })->all();

        $results = $this->bulkEmailService->sendBulkTemplatedEmail(
            $templateName,
            $fromEmail,
            $fromName,
            $destinations,
            $user,
            $defaultTemplateData,
            $tenantName,
            $message,
        );

        $this->processResults($results, $contactMails, $destinations);
    }

    /**
     * @param  Collection<int, Contact>  $contacts
     * @param  Collection<int, ContactMail>  $contactMails
     */
    private function sendViaMailer(Collection $contacts, Collection $contactMails, User $user): void
    {
        foreach ($contacts as $contact) {
            $contactMail = $contactMails->firstWhere('contact_id', $contact->id);

            $this->userMailerFactory->mailerFor($user)
                ->to($contact->email)
                ->send(new NewsletterMail($contactMail));

            $contactMail->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }
    }

    /**
     * SES sends the `Send` event later. Until then, `sent_at` is the only
     * record that SES accepted a mail, so a retry of the chunk skips it.
     *
     * @param  array<int, array{Status: string, Error?: string, MessageId?: string}>  $results
     * @param  Collection<int, ContactMail>  $contactMails
     * @param  array<int, array<string, mixed>>  $destinations
     */
    private function processResults(array $results, Collection $contactMails, array $destinations): void
    {
        $acceptedUuids = [];

        foreach ($results as $index => $result) {
            $status = $result['Status'] ?? '';

            if ($status === 'SUCCESS') {
                $acceptedUuids[] = $destinations[$index]['tags'][0]['Value'] ?? null;

                continue;
            }

            $contactMail = $contactMails->firstWhere('uuid', $destinations[$index]['tags'][0]['Value'] ?? null);

            if (! $contactMail) {
                continue;
            }

            $contactMail->forceFill([
                'status' => 'failed',
                'failure_reason' => $result['Error'] ?? $status,
            ])->save();

            Log::warning('SES rejected bulk email entry', [
                'contact_mail_id' => $contactMail->id,
                'status' => $status,
                'error' => $result['Error'] ?? null,
                'template_data' => $destinations[$index]['data'] ?? [],
            ]);
        }

        ContactMail::query()
            ->whereIn('uuid', array_filter($acceptedUuids))
            ->where('status', 'pending')
            ->update(['status' => 'sent', 'sent_at' => now()]);
    }
}
