<?php

namespace App\Enums;

enum ContactImportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * The import is still in the queue, so its progress can change.
     */
    public function inProgress(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
