<?php

namespace App\Mail;

use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklySummaryMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    protected ?array $computedMetrics = null;

    public function __construct(
        public Client $client,
        public User $recipient,
        public CarbonInterface $periodStart,
        public CarbonInterface $periodEnd,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        $subjectDate = $this->periodEnd
            ->timezone($this->recipientTimezone())
            ->format('F j');

        return new Envelope(
            subject: "Weekly summary · {$subjectDate}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.weekly-summary',
            with: [
                'client' => $this->client,
                'recipient' => $this->recipient,
                'metrics' => $this->metrics(),
                'periodStart' => $this->periodStart,
                'periodEnd' => $this->periodEnd,
                'timezone' => $this->recipientTimezone(),
                'dashboardUrl' => route('dashboard'),
            ],
        );
    }

    public function metrics(): array
    {
        if (isset($this->computedMetrics)) {
            return $this->computedMetrics;
        }

        $periodStartUtc = (clone $this->periodStart)->setTimezone('UTC');
        $periodEndUtc = (clone $this->periodEnd)->setTimezone('UTC');

        return $this->computedMetrics = [
            'new_subscribers' => Contact::query()
                ->where('client_id', $this->client->id)
                ->whereBetween('created_at', [$periodStartUtc, $periodEndUtc])
                ->count(),
            'new_unsubscribes' => Contact::query()
                ->where('client_id', $this->client->id)
                ->whereBetween('unsubscribed_at', [$periodStartUtc, $periodEndUtc])
                ->count(),
            'active_subscribers' => Contact::query()
                ->where('client_id', $this->client->id)
                ->whereNull('unsubscribed_at')
                ->count(),
            'mails_sent' => ContactMail::query()
                ->where('client_id', $this->client->id)
                ->whereNotNull('sent_at')
                ->whereBetween('sent_at', [$periodStartUtc, $periodEndUtc])
                ->count(),
        ];
    }

    public function attachments(): array
    {
        return [];
    }

    protected function recipientTimezone(): string
    {
        return $this->client->timezone ?: config('app.timezone');
    }
}
