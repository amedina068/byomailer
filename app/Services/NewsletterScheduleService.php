<?php

namespace App\Services;

use App\Enums\AbVariant;
use App\Enums\NewsletterStatus;
use App\Jobs\DispatchNewsletterBatchJob;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checks that a newsletter can send, and schedules, unschedules or queues it.
 * The dashboard, the REST API and the MCP server use it, so all run the same
 * checks and the same status changes.
 */
class NewsletterScheduleService
{
    /**
     * The error when `unschedule()` returns false: the scheduler or another
     * request queued the newsletter first.
     */
    public const UNSCHEDULE_FAILED = 'The newsletter started to send. It cannot return to draft.';

    /**
     * The error when `schedule()` or `sendNow()` returns false: the scheduler
     * or another request queued the newsletter first.
     */
    public const SCHEDULE_FAILED = 'The newsletter started to send. It cannot be scheduled or sent again.';

    public const SENDER_NOT_VERIFIED = 'Choose a verified sender identity before you schedule or send the newsletter.';

    public function __construct(
        private readonly ContactFilterService $contactFilterService,
        private readonly EmailSendingGate $emailSendingGate,
        private readonly SendableContentCheck $sendableContentCheck,
        private readonly SesPermissionChecker $sesPermissionChecker,
    ) {}

    /**
     * Run the checks that must pass before a newsletter is scheduled or sent.
     * The errors keep the keys that the dashboard form shows them under.
     * The warnings never block the send.
     *
     * @return array{errors: array<int|string, string>, warnings: array<string, string>, recipient_count: int}
     */
    public function sendCheck(User $user, Newsletter $newsletter): array
    {
        $contentProblems = $this->sendableContentCheck->problems($newsletter->message);

        if ($newsletter->ab_test_enabled) {
            $contentProblems += $this->sendableContentCheck->problems($newsletter->messageB, AbVariant::B);
        }

        if (! $this->hasVerifiedSender($newsletter)) {
            $contentProblems['email_identity_id'] = self::SENDER_NOT_VERIFIED;
        }

        $warnings = $this->warnings($newsletter);

        if (! empty($contentProblems)) {
            return ['errors' => $contentProblems, 'warnings' => $warnings, 'recipient_count' => 0];
        }

        $recipientCount = $this->recipientCount($user, $newsletter);

        $decision = $this->emailSendingGate->evaluate($user, $recipientCount);

        if (! $decision->allowed) {
            return [
                'errors' => [$decision->errorKey() => (string) $decision->message],
                'warnings' => $warnings,
                'recipient_count' => $recipientCount,
            ];
        }

        $missingPermissions = $this->sesPermissionChecker->getMissing($user, SesPermissionChecker::NEWSLETTER_BULK);

        if (! empty($missingPermissions)) {
            return [
                'errors' => ['ses_permissions' => 'Your AWS credentials are missing required SES permissions: '.implode(', ', $missingPermissions).'.'],
                'warnings' => $warnings,
                'recipient_count' => $recipientCount,
            ];
        }

        return ['errors' => [], 'warnings' => $warnings, 'recipient_count' => $recipientCount];
    }

    /**
     * Run `sendCheck()`, then schedule the newsletter for a future time or
     * queue it at once. Call it inside the transaction that saved the
     * newsletter, so a failure rolls back the other changes.
     *
     * @return list<string> the compliance warnings, which never block the send
     *
     * @throws ValidationException when a check fails, or when the scheduler
     *                             or another request queued the newsletter first
     */
    public function scheduleOrSend(User $user, Newsletter $newsletter, CarbonInterface $sendAt): array
    {
        $check = $this->sendCheck($user, $newsletter);

        if ($check['errors'] !== []) {
            throw ValidationException::withMessages($check['errors']);
        }

        $changed = $sendAt->isFuture()
            ? $this->schedule($newsletter, $sendAt)
            : $this->sendNow($newsletter, $sendAt);

        if (! $changed) {
            throw ValidationException::withMessages(['status' => self::SCHEDULE_FAILED]);
        }

        return array_values($check['warnings']);
    }

    /**
     * The compliance warnings of the newsletter and of its variant B.
     *
     * @return array<string, string>
     */
    public function warnings(Newsletter $newsletter): array
    {
        $warnings = $this->sendableContentCheck->warnings($newsletter->message);

        if ($newsletter->ab_test_enabled) {
            $warnings += $this->sendableContentCheck->warnings($newsletter->messageB, AbVariant::B);
        }

        return $warnings;
    }

    public function recipientCount(User $user, Newsletter $newsletter): int
    {
        return $this->contactFilterService->recipientCount(
            $user->client_id,
            $newsletter->effectiveFilters(),
            $newsletter,
        );
    }

    /**
     * Schedule a draft or scheduled newsletter. The update runs only while the
     * newsletter is mutable in the database, so a newsletter that the
     * scheduler or another request queued first does not change.
     *
     * @return bool true when this call scheduled the newsletter
     *
     * @throws ValidationException when the sender identity is gone or not verified
     */
    public function schedule(Newsletter $newsletter, CarbonInterface $scheduledAt): bool
    {
        return DB::transaction(function () use ($newsletter, $scheduledAt): bool {
            $this->lockSender($newsletter);

            return $this->transition($newsletter, [
                'status' => NewsletterStatus::Scheduled,
                'scheduled_at' => $scheduledAt,
            ]);
        });
    }

    /**
     * Return a draft or scheduled newsletter to draft. A newsletter that the
     * scheduler or another request queued first does not change.
     *
     * @return bool true when this call changed the newsletter
     */
    public function unschedule(Newsletter $newsletter): bool
    {
        return $this->transition($newsletter, [
            'status' => NewsletterStatus::Draft,
            'scheduled_at' => null,
        ]);
    }

    /**
     * Queue a draft or scheduled newsletter and dispatch its send batch. Only
     * the call that moves the newsletter to `queued` dispatches the batch, so
     * the web form, the API and the scheduler cannot start two sends. Inside a
     * transaction, the batch starts after the commit.
     *
     * @return bool true when this call queued the newsletter
     *
     * @throws ValidationException when the sender identity is gone or not verified
     */
    public function sendNow(Newsletter $newsletter, ?CarbonInterface $scheduledAt = null): bool
    {
        return DB::transaction(function () use ($newsletter, $scheduledAt): bool {
            $this->lockSender($newsletter);

            if (! $this->transition($newsletter, ['status' => NewsletterStatus::Queued, 'scheduled_at' => $scheduledAt ?? now()])) {
                return false;
            }

            DispatchNewsletterBatchJob::dispatch($newsletter)->afterCommit();

            return true;
        });
    }

    /**
     * Apply the attributes only while the newsletter is mutable in the
     * database. On success, the model gets the new values too.
     *
     * MySQL does not count a matched row that keeps its values. So when the
     * update affects no row, a mutable row that already holds the values is a
     * success, not a lost race.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transition(Newsletter $newsletter, array $attributes): bool
    {
        $mutableRow = fn (): Builder => Newsletter::query()->whereKey($newsletter->getKey())->mutable();

        $changed = $mutableRow()->update($attributes) === 1
            || $mutableRow()->where($attributes)->exists();

        if ($changed) {
            $newsletter->forceFill($attributes)->syncOriginal();
        }

        return $changed;
    }

    /**
     * Read the sender identity with a row lock. The identity delete reads the
     * same row with a lock before its usage check, so it cannot remove the
     * sender between this check and the status change.
     *
     * @throws ValidationException when the sender identity is gone or not verified
     */
    private function lockSender(Newsletter $newsletter): void
    {
        $identityId = $newsletter->message()->value('email_identity_id');

        $identity = $identityId === null
            ? null
            : EmailIdentity::query()->whereKey($identityId)->lockForUpdate()->first();

        if ($identity === null || ! $identity->isVerified()) {
            throw ValidationException::withMessages(['email_identity_id' => self::SENDER_NOT_VERIFIED]);
        }
    }

    /**
     * The newsletter sends from the sender of message A. It must be a verified
     * identity of the client of the newsletter.
     */
    private function hasVerifiedSender(Newsletter $newsletter): bool
    {
        $identity = $newsletter->message?->emailIdentity;

        return $identity !== null
            && $identity->client_id === $newsletter->client_id
            && $identity->isVerified();
    }
}
