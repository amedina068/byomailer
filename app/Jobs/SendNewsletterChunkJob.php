<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Support\Tenant;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SendNewsletterChunkJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The `ses-sending` limiter releases a chunk until a send slot is free.
     * Each release counts as an attempt, so the chunk has no attempt limit.
     * The first real exception fails the chunk.
     */
    public int $tries = 0;

    public int $maxExceptions = 1;

    /**
     * @param  array<int, int>  $contactIds
     */
    public function __construct(
        public Newsletter $newsletter,
        public User $user,
        public string $fromEmail,
        public ?string $fromName,
        public array $contactIds,
        public ?string $templateName,
        public Message $message,
        public ?string $tenantName,
    ) {
        $this->onQueue('newsletter');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimitedWithRedis('ses-sending'))->releaseAfter(1)];
    }

    public function handle(NewsletterTransport $transport): void
    {
        Tenant::run($this->newsletter->client_id, fn () => $this->sendChunk($transport));
    }

    private function sendChunk(NewsletterTransport $transport): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // The owner of the instance can deactivate the author while the chunks
        // of a large newsletter wait in the queue. Such a chunk sends nothing.
        if ($this->authorIsDeactivated()) {
            Log::warning('SendNewsletterChunkJob skipped: the author is deactivated', [
                'newsletter_id' => $this->newsletter->id,
                'user_id' => $this->user->id,
            ]);

            return;
        }

        // The contact can unsubscribe, be sunset or be blacklisted while the
        // chunk waits in the queue. Check the send rules again before sending.
        $contacts = Contact::withoutGlobalScopes()
            ->whereIn('id', $this->contactIds)
            ->sendable($this->newsletter->client_id)
            ->get()
            ->keyBy('id');

        if ($contacts->isEmpty()) {
            return;
        }

        $this->message->loadMissing('emailIdentity');

        $contentVariableDefaults = $this->newsletter->content_variables ?? [];
        $globalAttributeDefaults = $this->message->globalAttributeDefaults($this->newsletter->client_id);

        ContactMail::insertOrIgnore($contacts->map(
            fn (Contact $contact): array => $this->pendingContactMail($contact, $contentVariableDefaults, $globalAttributeDefaults)->getAttributes(),
        )->values()->all());

        $contactMails = $this->unsentContactMails($contacts->keys()->all());

        if ($contactMails->isEmpty()) {
            return;
        }

        foreach ($contactMails as $contactMail) {
            $contactMail->setRelation('message', $this->message);

            if ($contactMail->template_data === null || $contactMail->template_data === []) {
                $contactMail->forceFill([
                    'template_data' => $this->templateData($contacts[$contactMail->contact_id], $contactMail, $contentVariableDefaults, $globalAttributeDefaults),
                ])->save();
            }
        }

        $transport->sendBatch(
            $this->templateName,
            $this->fromEmail,
            $this->fromName,
            $contacts->only($contactMails->pluck('contact_id')->all())->values(),
            $contactMails,
            $this->user,
            $contentVariableDefaults,
            $this->tenantName,
            $this->message,
        );
    }

    private function authorIsDeactivated(): bool
    {
        return User::query()->whereKey($this->user->id)->whereNotNull('deactivated_at')->exists();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendNewsletterChunkJob failed', [
            'newsletter_id' => $this->newsletter->id,
            'contact_ids' => $this->contactIds,
            'error' => $exception?->getMessage(),
        ]);

        ContactMail::query()
            ->where('mailable_type', Newsletter::class)
            ->where('mailable_id', $this->newsletter->id)
            ->whereIn('contact_id', $this->contactIds)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'failure_reason' => $exception?->getMessage() ?? 'Chunk job failed',
            ]);
    }

    /**
     * Build the row in memory, so the chunk writes all its rows with one insert.
     *
     * @param  array<string, string|null>  $contentVariableDefaults
     * @param  array<string, string>  $globalAttributeDefaults
     */
    private function pendingContactMail(Contact $contact, array $contentVariableDefaults, array $globalAttributeDefaults): ContactMail
    {
        $contactMail = new ContactMail([
            'uuid' => (string) Str::uuid(),
            'client_id' => $this->newsletter->client_id,
            'contact_id' => $contact->id,
            'mailable_type' => Newsletter::class,
            'mailable_id' => $this->newsletter->id,
            'message_id' => $this->message->id,
            'subject' => $this->message->subject,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'scheduled_at' => now(),
            'status' => 'pending',
        ]);
        $contactMail->setRelation('message', $this->message);
        $contactMail->forceFill([
            'template_data' => $this->templateData($contact, $contactMail, $contentVariableDefaults, $globalAttributeDefaults),
        ])->updateTimestamps();

        return $contactMail;
    }

    /**
     * @param  array<string, string|null>  $contentVariableDefaults
     * @param  array<string, string>  $globalAttributeDefaults
     * @return array<string, string|null>
     */
    private function templateData(Contact $contact, ContactMail $contactMail, array $contentVariableDefaults, array $globalAttributeDefaults): array
    {
        $templateData = $this->message->resolveTemplateData($contact, $contactMail, $contentVariableDefaults, $globalAttributeDefaults);
        $templateData['unsubscribe_url'] = $contactMail->unsubscribe_link;
        $templateData['preferences_url'] = $contactMail->preferences_link;

        return $templateData;
    }

    /**
     * The rows of this chunk that SES did not accept yet. A worker can die
     * after the insert and before the send, so a retry finds `pending` rows.
     * SES acceptance sets `sent_at`, so those rows are not sent again.
     *
     * @param  array<int, int>  $contactIds
     * @return EloquentCollection<int, ContactMail>
     */
    private function unsentContactMails(array $contactIds): EloquentCollection
    {
        return ContactMail::query()
            ->where('mailable_type', Newsletter::class)
            ->where('mailable_id', $this->newsletter->id)
            ->whereIn('contact_id', $contactIds)
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->get();
    }
}
