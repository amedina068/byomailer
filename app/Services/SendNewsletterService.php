<?php

namespace App\Services;

use App\Enums\NewsletterStatus;
use App\Exceptions\MissingSesPermissionException;
use App\Exceptions\SendingBlockedException;
use App\Jobs\SendNewsletterChunkJob;
use App\Models\Newsletter;
use App\Models\User;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Log;

class SendNewsletterService
{
    /**
     * The number of recipient chunks for one `$batch->add()` call. Each call runs one
     * transaction on `job_batches` and one queue push, as in the Laravel docs.
     */
    public const CHUNKS_PER_ADD = 500;

    /**
     * The number of contact ids that one recipient query reads.
     */
    private const RECIPIENT_PAGE_SIZE = 1000;

    public function __construct(
        private NewsletterTransport $transport,
        private ContactFilterService $contactFilterService,
        private SesPermissionChecker $permissionChecker,
        private EmailSendingGate $sendingGate,
    ) {}

    /**
     * Add the chunk jobs of the newsletter to the batch. The newsletter can
     * change status while the send job waits in the queue, so only a
     * newsletter that is still `queued` sends.
     */
    public function send(Newsletter $newsletter, Batch $batch): void
    {
        $newsletter->refresh();

        if ($newsletter->status !== NewsletterStatus::Queued) {
            return;
        }

        $newsletter->loadMissing(['message', 'message.emailIdentity', 'messageA.emailIdentity', 'messageB.emailIdentity', 'user', 'user.sesCredential']);
        $message = $newsletter->message;
        $owner = $newsletter->user;

        if (! $message || ! $owner) {
            $newsletter->update(['status' => NewsletterStatus::Draft]);

            return;
        }

        $decision = $this->sendingGate->evaluate($owner);

        if (! $decision->allowed) {
            throw new SendingBlockedException($owner->id, $decision->reason);
        }

        $this->ensureSendPermissions($owner);

        $tenantName = $owner->sesCredential->tenant_name;

        $isAbTest = $newsletter->ab_test_enabled;

        if ($isAbTest) {
            $newsletter->loadMissing(['messageA.emailIdentity', 'messageB.emailIdentity']);
            $messageA = $newsletter->messageA;
            $messageB = $newsletter->messageB;

            if (! $messageA || ! $messageB) {
                $newsletter->update(['status' => NewsletterStatus::Draft]);

                return;
            }

            $fromEmail = $messageA->from_email;
            $fromName = $messageA->from_name;
        } else {
            $messageA = $message;
            $messageB = null;

            $fromEmail = $message->from_email;
            $fromName = $message->from_name;
        }

        // A retry of SendNewsletterJob reuses the templates of the first attempt,
        // because the chunk jobs in the queue already use them. A variant with
        // Liquid code has no template, so only one of the two names can be set.
        $templateNameA = $newsletter->template_name;
        $templateNameB = $newsletter->ab_template_name;

        if ($templateNameA === null && $templateNameB === null) {
            $templateNameA = $this->transport->createTemplate($newsletter, $owner, $messageA);
            $templateNameB = $messageB ? $this->transport->createTemplate($newsletter, $owner, $messageB) : null;

            if ($templateNameA !== null || $templateNameB !== null) {
                $newsletter->update([
                    'template_name' => $templateNameA,
                    'ab_template_name' => $templateNameB,
                ]);
            }
        }

        $filters = $newsletter->effectiveFilters();

        $recipientQuery = $this->contactFilterService->newsletterRecipientsQuery(
            $newsletter->client_id,
            $filters,
            $newsletter,
        );

        // A retry of SendNewsletterJob continues after the last contact that got a chunk job.
        $lastDispatchedContactId = $newsletter->last_dispatched_contact_id;

        if ($lastDispatchedContactId === null) {
            $newsletter->update(['total_contacts_reached' => $recipientQuery->count()]);
        } else {
            $recipientQuery->where('contacts.id', '>', $lastDispatchedContactId);
        }

        $chunkSize = $this->transport->getChunkSize($owner);
        $iterationChunkSize = $isAbTest ? $chunkSize * 2 : $chunkSize;

        Log::info('SendNewsletterService: starting recipient iteration', [
            'newsletter_id' => $newsletter->id,
            'total_contacts' => $newsletter->total_contacts_reached,
            'resume_after_contact_id' => $lastDispatchedContactId,
            'chunk_size' => $chunkSize,
            'iteration_chunk_size' => $iterationChunkSize,
            'ab_test' => $isAbTest,
            'filters' => $filters,
        ]);

        // An A/B send splits each chunk at random: half for variant A, half for variant B.
        $variants = $isAbTest ? [[$templateNameA, $messageA], [$templateNameB, $messageB]] : [[$templateNameA, $messageA]];
        $totalDispatched = 0;
        $chunkCount = 0;
        $contactIdChunks = $recipientQuery->lazyById(self::RECIPIENT_PAGE_SIZE)->pluck('id')->chunk($iterationChunkSize);

        foreach ($contactIdChunks->chunk(self::CHUNKS_PER_ADD) as $group) {
            $jobs = [];

            foreach ($group as $contactIdChunk) {
                $contactIds = $isAbTest ? $contactIdChunk->shuffle()->all() : $contactIdChunk->values()->all();
                $chunkCount++;
                $totalDispatched += count($contactIds);

                foreach (array_chunk($contactIds, (int) ceil(count($contactIds) / count($variants))) as $variant => $variantContactIds) {
                    $jobs[] = new SendNewsletterChunkJob(
                        newsletter: $newsletter,
                        user: $owner,
                        fromEmail: $fromEmail,
                        fromName: $fromName,
                        contactIds: $variantContactIds,
                        templateName: $variants[$variant][0],
                        message: $variants[$variant][1],
                        tenantName: $tenantName,
                    );
                }
            }

            $batch->add($jobs);
            $newsletter->update(['last_dispatched_contact_id' => $group->last()->last()]);
        }

        Log::info('SendNewsletterService: recipient iteration complete', [
            'newsletter_id' => $newsletter->id,
            'total_contacts' => $newsletter->total_contacts_reached,
            'total_dispatched' => $totalDispatched,
            'chunk_count' => $chunkCount,
            'chunk_size' => $chunkSize,
        ]);
    }

    /**
     * @throws MissingSesPermissionException
     */
    private function ensureSendPermissions(User $owner): void
    {
        $required = $this->transport->usesSesApi()
            ? SesPermissionChecker::NEWSLETTER_BULK
            : SesPermissionChecker::SEND_EMAIL;

        $this->permissionChecker->ensureAll($owner, $required);
    }
}
