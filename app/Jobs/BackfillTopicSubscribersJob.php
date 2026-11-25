<?php

namespace App\Jobs;

use App\Models\Topic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class BackfillTopicSubscribersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public readonly int $topicId) {}

    public function handle(): void
    {
        $topic = Topic::withoutGlobalScopes()->find($this->topicId);

        if (! $topic) {
            return;
        }

        $now = now();

        DB::table('contact_list')
            ->join('contacts', 'contacts.id', '=', 'contact_list.contact_id')
            ->join('lists', 'lists.id', '=', 'contact_list.list_id')
            ->where('contact_list.list_id', $topic->list_id)
            ->where('contacts.client_id', $topic->client_id)
            ->where(fn ($query) => $query
                ->where('lists.opt_in_enabled', false)
                ->orWhereNotNull('contact_list.opted_in_at'))
            ->select('contact_list.contact_id')
            ->orderBy('contact_list.contact_id')
            ->chunk(1000, function ($rows) use ($topic, $now): void {
                $insert = $rows->map(fn ($row): array => [
                    'contact_id' => $row->contact_id,
                    'topic_id' => $topic->id,
                    'subscribed_at' => $now,
                    'unsubscribed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('contact_topic')->insertOrIgnore($insert);
            });
    }
}
