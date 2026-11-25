<?php

namespace App\Jobs;

use App\Actions\UpsertContact;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\User;
use App\Services\ContactMembership;
use App\Support\Tenant;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessContactImportBatchJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * @param  array<int, array{email: string, name: ?string, tags: ?string, attributes: array<string, string>}>  $rows
     */
    public function __construct(
        public readonly User $user,
        public readonly MailList $mailList,
        public readonly ContactImport $contactImport,
        public readonly array $rows,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(UpsertContact $upsertContact, ContactMembership $membership): void
    {
        Tenant::run($this->mailList->client_id, fn () => $this->importRows($upsertContact, $membership));
    }

    private function importRows(UpsertContact $upsertContact, ContactMembership $membership): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $processed = 0;
        $skipped = 0;

        DB::transaction(function () use ($upsertContact, $membership, &$processed, &$skipped): void {
            foreach ($this->rows as $row) {
                if ($this->importRow($upsertContact, $membership, $row)) {
                    $processed++;

                    continue;
                }

                $skipped++;
            }
        }, attempts: 5);

        $this->contactImport->incrementProcessed($processed);
        $this->contactImport->incrementSkipped($skipped);
    }

    /**
     * An empty name or attribute in the file keeps the current value.
     *
     * @param  array{email: string, name: ?string, tags: ?string, attributes: array<string, string>}  $row
     */
    private function importRow(UpsertContact $upsertContact, ContactMembership $membership, array $row): bool
    {
        $changes = [
            'extra_attributes' => array_filter($row['attributes'], fn (?string $value): bool => $value !== null && $value !== ''),
        ];

        if (filled($row['name'])) {
            $changes['name'] = $row['name'];
        }

        $contact = $upsertContact->handle($this->user, $row['email'], $changes);

        $contact->syncLists([$this->mailList->id], true);

        if (! $this->mailList->opt_in_enabled) {
            $contact->lists()->updateExistingPivot($this->mailList->id, [
                'opted_in_at' => now(),
            ]);
        }

        if (filled($row['tags'])) {
            $membership->attachTags($this->user, $contact, explode(',', $row['tags']));
        }

        return true;
    }
}
