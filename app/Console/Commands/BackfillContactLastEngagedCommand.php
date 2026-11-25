<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactMailClick;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillContactLastEngagedCommand extends Command
{
    protected $signature = 'contacts:backfill-last-engaged {--chunk=1000 : Contacts per id window}';

    protected $description = 'Set contacts.last_engaged_at from the latest recorded click. Moves the value forward only.';

    public function handle(): int
    {
        $clicks = (new ContactMailClick)->getTable();
        $updated = 0;

        $latestClick = DB::table($clicks)
            ->selectRaw('max('.DB::getQueryGrammar()->wrap($clicks.'.clicked_at').')')
            ->whereColumn($clicks.'.contact_id', 'contacts.id');

        Contact::withoutGlobalScopes()
            ->select('id')
            ->chunkById((int) $this->option('chunk'), function (Collection $contacts) use ($clicks, $latestClick, &$updated): void {
                $updated += Contact::withoutGlobalScopes()
                    ->toBase()
                    ->whereBetween('id', [$contacts->first()->id, $contacts->last()->id])
                    ->whereExists(fn (Builder $query) => $query
                        ->from($clicks)
                        ->whereColumn($clicks.'.contact_id', 'contacts.id')
                        ->where(fn (Builder $newer) => $newer
                            ->whereNull('contacts.last_engaged_at')
                            ->orWhereColumn($clicks.'.clicked_at', '>', 'contacts.last_engaged_at')))
                    ->update(['last_engaged_at' => DB::raw('('.$latestClick->toSql().')')]);
            });

        $this->info("Updated {$updated} contact(s).");

        return self::SUCCESS;
    }
}
