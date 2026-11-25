<?php

namespace App\Jobs;

use App\Enums\SequenceStatus;
use App\Events\SequenceStepSent;
use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\ContactFilterService;
use App\Services\EmailSendingGate;
use App\Services\SendDecision;
use App\Services\SequenceStepScheduler;
use App\Services\SesPermissionChecker;
use App\Services\UserMailerFactory;
use App\Support\Tenant;
use Aws\Exception\AwsException;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimitedWithRedis;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a chunk of sequence mails that the sequence cron claimed. The chunk
 * goes through the same `ses-sending` limiter as newsletter chunks, so a
 * large sequence cannot exceed the SES max send rate of the user.
 */
class SendSequenceContactMailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * The `ses-sending` limiter releases the job until a send slot is free.
     * Each release counts as an attempt, so the job has no attempt limit.
     * The first real exception fails the job.
     */
    public int $tries = 0;

    public int $maxExceptions = 1;

    /**
     * @var array<int, array<string, string>>
     */
    private array $globalDefaultsByStep = [];

    /**
     * @param  array<int, int>  $contactMailIds
     * @param  CarbonInterface  $claimToken  The reserved_at value the cron wrote for these rows.
     */
    public function __construct(
        public User $user,
        public array $contactMailIds,
        public CarbonInterface $claimToken,
    ) {
        $this->onQueue('sequences');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimitedWithRedis('ses-sending'))->releaseAfter(1)];
    }

    public function handle(
        EmailSendingGate $gate,
        ContactFilterService $contactFilterService,
        SesPermissionChecker $permissionChecker,
        UserMailerFactory $userMailerFactory,
        SequenceStepScheduler $sequenceStepScheduler,
    ): void {
        Tenant::run($this->user->client_id, fn () => $this->sendClaimedMails(
            $gate,
            $contactFilterService,
            $permissionChecker,
            $userMailerFactory,
            $sequenceStepScheduler,
        ));
    }

    private function sendClaimedMails(
        EmailSendingGate $gate,
        ContactFilterService $contactFilterService,
        SesPermissionChecker $permissionChecker,
        UserMailerFactory $userMailerFactory,
        SequenceStepScheduler $sequenceStepScheduler,
    ): void {
        $this->globalDefaultsByStep = [];

        $mails = $this->claimedMails()
            ->with([
                'contact' => fn ($query) => $query->withoutGlobalScopes(),
                'mailable' => fn ($query) => $query->morphWith([
                    SequenceStep::class => [
                        'sequence.segment' => fn ($q) => $q->withoutGlobalScopes(),
                        'message.emailIdentity',
                    ],
                ]),
            ])
            ->get();

        if ($mails->isEmpty()) {
            return;
        }

        $decision = $gate->evaluate($this->user);
        $hasSendPermission = $this->hasSendPermission($permissionChecker);

        foreach ($mails as $mail) {
            $this->sendMail($mail, $decision, $hasSendPermission, $contactFilterService, $userMailerFactory, $sequenceStepScheduler);
        }
    }

    /**
     * The rows of this chunk that still hold this job's claim token and are
     * unsent, bound to the client of the job user. A row that a later tick
     * claimed again has a new token, so this job skips it.
     */
    private function claimedMails(): Builder
    {
        return ContactMail::withoutGlobalScopes()
            ->whereIn('id', $this->contactMailIds)
            ->where('client_id', $this->user->client_id)
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->where('reserved_at', $this->claimToken);
    }

    private function sendMail(
        ContactMail $mail,
        SendDecision $decision,
        bool $hasSendPermission,
        ContactFilterService $contactFilterService,
        UserMailerFactory $userMailerFactory,
        SequenceStepScheduler $sequenceStepScheduler,
    ): void {
        $contact = $mail->contact;

        if (! $contact) {
            $mail->delete();

            return;
        }

        if (! $decision->allowed) {
            $this->markFailed($mail, $decision->reason);

            return;
        }

        // A suppressed address (hard bounce or complaint) must never be mailed
        // again. Sequences enrol contacts once and keep sending for weeks, so
        // without this check a bounced address is retried at every step.
        if ($contactFilterService->isBlacklisted($contact->email, $this->user->client_id)) {
            $this->markFailed($mail, 'blacklisted');

            return;
        }

        // The contact can unsubscribe, or the sweep can mark it sunset, after
        // the cron claimed the row.
        if ($contact->isExcludedFromSending()) {
            $this->markFailed($mail, $contact->unsubscribed_at !== null ? 'unsubscribed' : 'sunset');

            return;
        }

        if (! $hasSendPermission) {
            $this->markFailed($mail, SesPermissionChecker::FAILURE_REASON);

            return;
        }

        if (! $this->belongsToTheSequence($mail)) {
            $mail->delete();

            return;
        }

        if (! $this->isLive($mail)) {
            $mail->forceFill(['reserved_at' => null])->save();

            return;
        }

        if (! $this->contactMatchesSequenceFilters($mail, $contactFilterService)) {
            $this->exitSequence($mail, $sequenceStepScheduler);

            return;
        }

        if (! $this->contactMatchesStepFilters($mail, $contactFilterService)) {
            $this->skipStep($mail, $sequenceStepScheduler);

            return;
        }

        $this->fillSendData($mail);

        try {
            $userMailerFactory->mailerFor($this->user)
                ->to($contact->email, $contact->name)
                ->send(new NewsletterMail($mail));
        } catch (Throwable $e) {
            if ($this->isSesThrottle($e)) {
                $mail->forceFill(['reserved_at' => null])->save();

                return;
            }

            $this->markFailed($mail, mb_substr($e->getMessage(), 0, 500));

            report($e);

            return;
        }

        $this->markSent($mail);
    }

    /**
     * Mark the row sent now. The SES SEND event can arrive after the next
     * tick, and a row that is still pending is sent again. This job is the only
     * code that moves a sequence mail from `pending` to `sent`, so
     * `SequenceStepSent` fires one time for each mail.
     */
    private function markSent(ContactMail $mail): void
    {
        $mail->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'reserved_at' => null,
        ])->save();

        if ($mail->mailable instanceof SequenceStep && $mail->contact instanceof Contact) {
            SequenceStepSent::dispatch($mail->mailable, $mail->contact);
        }
    }

    /**
     * SES throttling is not a mail failure. The row stays pending and a later
     * tick claims it again.
     */
    private function isSesThrottle(Throwable $e): bool
    {
        if ($e instanceof AwsException && $e->getAwsErrorCode() === 'Throttling') {
            return true;
        }

        return Str::contains($e->getMessage(), 'Maximum sending rate exceeded');
    }

    private function markFailed(ContactMail $mail, ?string $reason): void
    {
        $mail->forceFill([
            'status' => 'failed',
            'failure_reason' => $reason,
            'reserved_at' => null,
        ])->save();
    }

    private function hasSendPermission(SesPermissionChecker $permissionChecker): bool
    {
        if ($permissionChecker->hasAll($this->user, SesPermissionChecker::SEND_EMAIL)) {
            return true;
        }

        Log::warning('Sequence mail skipped: user missing ses:SendEmail permission.', [
            'user_id' => $this->user->id,
        ]);

        return false;
    }

    /**
     * A mail whose step, sequence or message is gone, or whose contact left
     * the sequence, can never send.
     */
    private function belongsToTheSequence(ContactMail $mail): bool
    {
        $step = $mail->sequenceStep;
        $sequence = $step?->sequence;

        return $sequence
            && $step->message
            && $mail->contact->sequences()->where('sequences.id', $sequence->id)->exists();
    }

    /**
     * A paused step or sequence keeps its mails pending, so they send when it
     * goes live again.
     */
    private function isLive(ContactMail $mail): bool
    {
        return $mail->sequenceStep->status === SequenceStatus::Live
            && $mail->sequenceStep->sequence->status === SequenceStatus::Live;
    }

    private function contactMatchesSequenceFilters(ContactMail $mail, ContactFilterService $contactFilterService): bool
    {
        $filters = $mail->sequenceStep->sequence->effectiveFilters();

        return empty($filters) || $contactFilterService->contactMatchesFilters($mail->contact, $filters);
    }

    private function contactMatchesStepFilters(ContactMail $mail, ContactFilterService $contactFilterService): bool
    {
        $filters = $mail->sequenceStep->filters ?? [];

        return empty($filters) || $contactFilterService->contactMatchesFilters($mail->contact, $filters);
    }

    /**
     * A contact who does not match the step filters skips only this step's
     * email. The row stays as `skipped` so the step is never scheduled again,
     * and the contact moves on as if the email was delivered.
     */
    private function skipStep(ContactMail $mail, SequenceStepScheduler $sequenceStepScheduler): void
    {
        try {
            DB::transaction(function () use ($mail, $sequenceStepScheduler): void {
                $mail->forceFill(['status' => 'skipped', 'reserved_at' => null])->save();

                $sequenceStepScheduler->scheduleNextStep($mail);
            });
        } catch (Throwable $e) {
            $this->markFailed($mail, mb_substr($e->getMessage(), 0, 500));

            report($e);
        }
    }

    /**
     * A contact who no longer matches the sequence filters leaves the
     * sequence. The mail stays as `skipped`, and the contact finishes the
     * sequence.
     */
    private function exitSequence(ContactMail $mail, SequenceStepScheduler $sequenceStepScheduler): void
    {
        try {
            $sequenceStepScheduler->exitSequence($mail->contact, $mail->sequenceStep->sequence);
        } catch (Throwable $e) {
            $this->markFailed($mail, mb_substr($e->getMessage(), 0, 500));

            report($e);
        }
    }

    private function fillSendData(ContactMail $mail): void
    {
        $step = $mail->sequenceStep;
        $sequence = $step->sequence;
        $message = $step->message;
        $contact = $mail->contact;

        $globalAttributeDefaults = $this->globalDefaultsByStep[$step->id]
            ??= $message->globalAttributeDefaults($this->user->client_id);

        $templateData = $message->resolveTemplateData(
            $contact,
            $mail,
            $sequence->content_variables ?? [],
            $globalAttributeDefaults,
        );

        $mail->forceFill([
            'subject' => $message->subject,
            'from_email' => $message->emailIdentity->email,
            'from_name' => $sequence->senderNameFor($message->emailIdentity),
            'template_data' => $templateData,
        ])->save();
    }
}
