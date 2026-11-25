<?php

namespace App\Imports;

use App\Enums\ContactImportStatus;
use App\Jobs\ProcessContactImportBatchJob;
use App\Models\ContactImport;
use App\Models\MailList;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Throwable;

class MailListContactsImport implements ToCollection
{
    /**
     * Normalized header names that the import reads as the email column.
     */
    private const EMAIL_HEADERS = ['email', 'e_mail', 'email_address', 'e_mail_address'];

    private const NO_ROWS_MESSAGE = 'The file has no contact rows. Add one row for each contact below the header row.';

    private int $queued = 0;

    private int $skipped = 0;

    private ?string $problem = null;

    private ?ContactImport $contactImport = null;

    public function __construct(
        private readonly User $user,
        private readonly MailList $mailList,
        private readonly string $originalFilename,
    ) {}

    public function collection(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            $this->problem = self::NO_ROWS_MESSAGE;

            return;
        }

        $headerRow = $rows->shift();
        $headerCollection = $headerRow instanceof Collection ? $headerRow : collect($headerRow);

        $headers = $this->normalizeHeaders($headerCollection);
        $emailIndex = $this->emailColumnIndex($headers);

        if ($emailIndex === null) {
            $this->problem = 'The file has no email column. The first row must have a column named "email".';

            return;
        }

        $validRows = $this->validRows($rows, $headers, $emailIndex);

        if ($validRows === []) {
            $this->problem = $this->skipped > 0
                ? 'No row in the file has a valid email address. Check the values in the email column.'
                : self::NO_ROWS_MESSAGE;

            return;
        }

        $this->queued = count($validRows);

        $this->contactImport = ContactImport::query()->create([
            'user_id' => $this->user->id,
            'list_id' => $this->mailList->id,
            'status' => ContactImportStatus::Pending,
            'original_filename' => $this->originalFilename,
            'total_count' => $this->queued + $this->skipped,
            'skipped_count' => $this->skipped,
        ]);

        $jobs = collect($validRows)
            ->chunk(50)
            ->map(fn (Collection $chunk) => new ProcessContactImportBatchJob(
                $this->user,
                $this->mailList,
                $this->contactImport,
                $chunk->values()->all(),
            ))
            ->all();

        $batch = Bus::batch($jobs)
            ->name("Import contacts: {$this->originalFilename}")
            ->allowFailures()
            ->then(fn (Batch $batch) => ContactImport::query()
                ->find($batch->options['contact_import_id'] ?? null)?->markAsCompleted())
            ->catch(fn (Batch $batch, Throwable $e) => ContactImport::query()
                ->find($batch->options['contact_import_id'] ?? null)?->markAsFailed($e->getMessage()))
            ->finally(function (Batch $batch): void {
                $import = ContactImport::query()->find($batch->options['contact_import_id'] ?? null);

                if ($import && $import->isProcessing()) {
                    $import->markAsCompleted();
                }
            })
            ->withOption('contact_import_id', $this->contactImport->id)
            ->dispatch();

        $this->contactImport->markAsProcessing($batch->id);
    }

    /**
     * The number of rows with a valid email that the import sent to the queue.
     */
    public function queuedCount(): int
    {
        return $this->queued;
    }

    public function skippedCount(): int
    {
        return $this->skipped;
    }

    public function getContactImport(): ?ContactImport
    {
        return $this->contactImport;
    }

    /**
     * Why the file gave no contacts to import, or null when it gave contacts.
     */
    public function problem(): ?string
    {
        return $this->problem;
    }

    /**
     * Map the data rows. Blank rows are ignored. A row without a valid email
     * adds one to the skipped count.
     *
     * @param  Collection<int, mixed>  $rows
     * @param  array<int, array{original: string, normalized: string}|null>  $headers
     * @return list<array{email: string, name: ?string, tags: ?string, attributes: array<string, string>}>
     */
    private function validRows(Collection $rows, array $headers, int $emailIndex): array
    {
        $validRows = [];

        foreach ($rows as $row) {
            $rowCollection = $row instanceof Collection ? $row : collect($row);

            if ($this->isBlankRow($rowCollection)) {
                continue;
            }

            $rowData = $this->mapRow($rowCollection, $headers, $emailIndex);

            if (! $rowData) {
                $this->skipped++;

                continue;
            }

            $validRows[] = $rowData;
        }

        return $validRows;
    }

    /**
     * @param  array<int, array{original: string, normalized: string}|null>  $headers
     * @return array{email: string, name: ?string, tags: ?string, attributes: array<string, string>}|null
     */
    private function mapRow(Collection $row, array $headers, int $emailIndex): ?array
    {
        $email = null;
        $name = null;
        $tags = null;
        $attributes = [];

        foreach ($headers as $index => $header) {
            $value = $this->stringValue($row[$index] ?? null);

            if (! $header) {
                continue;
            }

            if ($index === $emailIndex) {
                $email = $value;

                continue;
            }

            if ($header['normalized'] === 'name') {
                $name = $value;

                continue;
            }

            if ($header['normalized'] === 'tags') {
                $tags = $value;

                continue;
            }

            if ($value !== null) {
                $attributes[$header['original']] = $value;
            }
        }

        if (! $email || ! $this->isValidEmail($email)) {
            return null;
        }

        return [
            'email' => $email,
            'name' => $name,
            'tags' => $tags,
            'attributes' => $attributes,
        ];
    }

    /**
     * @return array<int, array{original: string, normalized: string}|null>
     */
    private function normalizeHeaders(Collection $row): array
    {
        return $row->map(function ($value) {
            if (! is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            return [
                'original' => $trimmed,
                'normalized' => $this->normalizeKey($trimmed),
            ];
        })->toArray();
    }

    /**
     * @param  array<int, array{original: string, normalized: string}|null>  $headers
     */
    private function emailColumnIndex(array $headers): ?int
    {
        foreach ($headers as $index => $header) {
            if ($header && in_array($header['normalized'], self::EMAIL_HEADERS, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, mixed>  $row
     */
    private function isBlankRow(Collection $row): bool
    {
        return $row->every(fn (mixed $value): bool => $this->stringValue($value) === null);
    }

    private function normalizeKey(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/i', '_')
            ->trim('_');
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            $string = trim((string) $value);

            return $string === '' ? null : $string;
        }

        return null;
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
