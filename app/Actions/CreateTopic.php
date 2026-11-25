<?php

namespace App\Actions;

use App\Jobs\BackfillTopicSubscribersJob;
use App\Models\MailList;
use App\Models\Topic;
use App\Models\User;

class CreateTopic
{
    /**
     * Create a topic in the list. The user is the author. A job then
     * subscribes each contact of the list to the topic.
     *
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(User $user, MailList $list, array $data): Topic
    {
        $topic = Topic::create([
            'client_id' => $list->client_id,
            'user_id' => $user->id,
            'list_id' => $list->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        BackfillTopicSubscribersJob::dispatch($topic->id);

        return $topic;
    }
}
