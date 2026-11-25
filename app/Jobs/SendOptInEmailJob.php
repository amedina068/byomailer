<?php

namespace App\Jobs;

use App\Mail\OptInMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\MailList;
use App\Services\EmailSendingGate;
use App\Services\UserMailerFactory;
use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendOptInEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $maxExceptions = 1;

    public function __construct(
        public readonly Contact $contact,
        public readonly MailList $mailList,
    ) {
        $this->onQueue('sequences');
    }

    public function handle(UserMailerFactory $userMailerFactory, EmailSendingGate $gate): void
    {
        Tenant::run($this->mailList->client_id, fn () => $this->send($userMailerFactory, $gate));
    }

    /**
     * Skip a contact that BYOMailer must not mail: unsubscribed, sunset,
     * suppressed, or already confirmed. When the sending gate blocks the
     * list owner, record the opt-in mail as failed with the gate reason.
     */
    private function send(UserMailerFactory $userMailerFactory, EmailSendingGate $gate): void
    {
        $message = $this->mailList->message;

        if (! $message
            || $this->contact->isExcludedFromSending()
            || $this->contact->suppression() !== null
            || $this->alreadyConfirmed()) {
            return;
        }

        $decision = $gate->evaluate($this->mailList->user);
        $templateData = $message->templateData($this->contact, new ContactMail);

        $contactMail = ContactMail::withoutGlobalScopes()->updateOrCreate(
            [
                'contact_id' => $this->contact->id,
                'mailable_type' => MailList::class,
                'mailable_id' => $this->mailList->id,
            ],
            [
                'client_id' => $this->contact->client_id,
                'message_id' => $message->id,
                'subject' => $message->subject,
                'from_email' => $message->from_email,
                'from_name' => $message->from_name,
                'template_data' => $templateData,
                'status' => $decision->allowed ? 'pending' : 'failed',
                'sent_at' => null,
                'failure_reason' => $decision->reason,
            ],
        );

        if (! $decision->allowed) {
            return;
        }

        $userMailerFactory->mailerFor($this->mailList->user)
            ->to($this->contact->email)
            ->send(new OptInMail($contactMail));

        $contactMail->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
    }

    public function failed(?Throwable $exception): void
    {
        ContactMail::withoutGlobalScopes()
            ->where('client_id', $this->mailList->client_id)
            ->where('contact_id', $this->contact->id)
            ->where('mailable_type', MailList::class)
            ->where('mailable_id', $this->mailList->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'failure_reason' => $exception?->getMessage() ?? 'Opt-in job failed',
            ]);
    }

    private function alreadyConfirmed(): bool
    {
        return $this->contact->lists()
            ->whereKey($this->mailList->id)
            ->wherePivotNotNull('opted_in_at')
            ->exists();
    }
}
