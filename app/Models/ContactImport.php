<?php

namespace App\Models;

use App\Enums\ContactImportStatus;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\ContactImportFactory;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Bus;

/**
 * @property ContactImportStatus $status
 */
class ContactImport extends Model
{
    /** @use HasFactory<ContactImportFactory> */
    use BelongsToClient, HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'skipped_count' => 'integer',
            'status' => ContactImportStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MailList, $this>
     */
    public function mailList(): BelongsTo
    {
        return $this->belongsTo(MailList::class, 'list_id');
    }

    public function batch(): ?Batch
    {
        if (! $this->batch_id) {
            return null;
        }

        return Bus::findBatch($this->batch_id);
    }

    public function incrementProcessed(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->increment('processed_count', $count);
        $this->refresh();
    }

    public function incrementSkipped(int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $this->increment('skipped_count', $count);
        $this->refresh();
    }

    public function markAsProcessing(string $batchId): void
    {
        $this->forceFill([
            'batch_id' => $batchId,
            'status' => ContactImportStatus::Processing,
        ])->save();
    }

    public function markAsCompleted(): void
    {
        $this->forceFill([
            'status' => ContactImportStatus::Completed,
            'error_message' => null,
        ])->save();
    }

    public function markAsFailed(?string $message = null): void
    {
        $this->forceFill([
            'status' => ContactImportStatus::Failed,
            'error_message' => $message,
        ])->save();
    }

    public function isPending(): bool
    {
        return $this->status === ContactImportStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === ContactImportStatus::Processing;
    }

    public function isCompleted(): bool
    {
        return $this->status === ContactImportStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === ContactImportStatus::Failed;
    }
}
