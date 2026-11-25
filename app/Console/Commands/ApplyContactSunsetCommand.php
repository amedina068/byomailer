<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\SequenceStep;
use App\Services\SunsetPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ApplyContactSunsetCommand extends Command
{
    protected $signature = 'contacts:apply-sunset {--chunk=1000 : Contacts per update}';

    protected $description = 'Mark contacts with no clicks in their client sunset period as sunset.';

    public function handle(SunsetPolicy $policy): int
    {
        $chunk = (int) $this->option('chunk');
        $marked = 0;

        Client::query()
            ->whereNotNull('sunset_inactive_days')
            ->select(['id', 'sunset_inactive_days'])
            ->chunkById(100, function (Collection $clients) use ($policy, $chunk, &$marked): void {
                foreach ($clients as $client) {
                    $cutoff = now()->subDays($client->sunset_inactive_days);

                    foreach ($policy->candidateQueries($client->id, $cutoff) as $candidates) {
                        $candidates->select('id')->chunkById($chunk, function (Collection $contacts) use ($client, &$marked): void {
                            $ids = $contacts->pluck('id');

                            $marked += Contact::withoutGlobalScopes()
                                ->toBase()
                                ->whereIn('id', $ids)
                                ->update(['sunset_at' => now()]);

                            $this->failPendingSequenceMails($client->id, $ids);
                        });
                    }

                    Cache::forget(SunsetPolicy::previewCacheKey($client->id, $client->sunset_inactive_days));
                }
            });

        $this->info("Marked {$marked} contact(s) as sunset.");

        return self::SUCCESS;
    }

    /**
     * The bulk update fires no observer, so pending sequence mails of sunset
     * contacts are closed here. Otherwise they stay pending forever.
     *
     * @param  Collection<int, int>  $contactIds
     */
    private function failPendingSequenceMails(int $clientId, Collection $contactIds): void
    {
        ContactMail::withoutGlobalScopes()
            ->toBase()
            ->where('client_id', $clientId)
            ->whereIn('contact_id', $contactIds)
            ->where('mailable_type', SequenceStep::class)
            ->where('status', 'pending')
            ->whereNull('sent_at')
            ->update(['status' => 'failed', 'failure_reason' => 'sunset', 'updated_at' => now()]);
    }
}
