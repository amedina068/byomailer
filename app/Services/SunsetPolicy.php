<?php

namespace App\Services;

use App\Models\Contact;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Selects the contacts that the sunset sweep marks as inactive. A contact is a
 * candidate when its last click (or its signup, when it never clicked) is older
 * than the cutoff, and it still got enough mail after the cutoff. A contact
 * that nobody mailed is not inactive.
 */
class SunsetPolicy
{
    public const MIN_MAILS_IN_WINDOW = 3;

    /**
     * Two queries instead of one `COALESCE`, so each can use the
     * `(client_id, sunset_at, last_engaged_at, created_at)` index.
     *
     * @return array{clicked: Builder<Contact>, never_clicked: Builder<Contact>}
     */
    public function candidateQueries(int $clientId, CarbonInterface $cutoff): array
    {
        return [
            'clicked' => $this->baseQuery($clientId, $cutoff)
                ->where('last_engaged_at', '<', $cutoff),
            'never_clicked' => $this->baseQuery($clientId, $cutoff)
                ->whereNull('last_engaged_at')
                ->where('created_at', '<', $cutoff),
        ];
    }

    /**
     * The cache key of the Profile preview count. The sweep and reactivate
     * forget it, so the preview does not show a stale count.
     */
    public static function previewCacheKey(int $clientId, int $days): string
    {
        return "sunset-preview:{$clientId}:{$days}";
    }

    /**
     * The number of contacts that the next sweep marks for this client.
     */
    public function candidateCount(int $clientId, CarbonInterface $cutoff): int
    {
        return collect($this->candidateQueries($clientId, $cutoff))
            ->sum(fn (Builder $candidates): int => $candidates->count());
    }

    /**
     * @return Builder<Contact>
     */
    private function baseQuery(int $clientId, CarbonInterface $cutoff): Builder
    {
        return Contact::withoutGlobalScopes()
            ->where('client_id', $clientId)
            ->whereNull('sunset_at')
            ->whereNull('unsubscribed_at')
            ->whereHas(
                'mails',
                fn (Builder $mails) => $mails->withoutGlobalScopes()->where('sent_at', '>=', $cutoff),
                '>=',
                self::MIN_MAILS_IN_WINDOW,
            );
    }
}
