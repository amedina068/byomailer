<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Mail\WeeklySummaryMail;
use App\Models\Client;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class SendWeeklySummaryMail extends Command
{
    protected $signature = 'summary:send-weekly {--client= : Limit the summary to a specific client ID}';

    protected $description = 'Send the Friday weekly summary email to every active user';

    public function handle(): int
    {
        $clientFilter = $this->option('client');

        $query = Client::query()
            ->when($clientFilter, fn (Builder $builder): Builder => $builder->whereKey($clientFilter))
            ->whereHas('users', fn (Builder $builder): Builder => $this->scopeActiveUsers($builder))
            ->with(['users' => fn ($builder) => $this->scopeActiveUsers($builder)->orderBy('id')]);

        $queuedMails = 0;

        $query->chunkById(50, function (Collection $clients) use (&$queuedMails): void {
            foreach ($clients as $client) {
                $firstUser = $client->users->sortBy('id')->first();

                if (! $firstUser) {
                    continue;
                }

                $timezone = $client->timezone ?: config('app.timezone');
                $localizedNow = CarbonImmutable::now($timezone);

                if (! $this->shouldSendSummary($localizedNow)) {
                    continue;
                }

                $periodEndLocal = $localizedNow;
                $periodStartLocal = $localizedNow->subWeek()->setTime(17, 0);

                // The queued mail carries the tenant, so the worker builds the summary as this client.
                Tenant::run($client->id, function () use ($client, $periodStartLocal, $periodEndLocal, &$queuedMails): void {
                    foreach ($client->users as $user) {
                        Mail::to($user)->queue(
                            new WeeklySummaryMail(
                                $client,
                                $user,
                                $periodStartLocal,
                                $periodEndLocal
                            )
                        );

                        $queuedMails++;
                    }
                });
            }
        });

        $message = $queuedMails === 0
            ? 'No summaries were queued this hour.'
            : "Queued {$queuedMails} summary mail(s).";

        $this->info($message);

        return self::SUCCESS;
    }

    protected function scopeActiveUsers(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->whereNull('archived_at')
            ->where('role', '!=', UserRole::Invited->value);
    }

    protected function shouldSendSummary(CarbonImmutable $localizedNow): bool
    {
        return $localizedNow->dayOfWeek === CarbonInterface::FRIDAY
            && (int) $localizedNow->format('G') === 17;
    }
}
