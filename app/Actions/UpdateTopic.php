<?php

namespace App\Actions;

use App\Models\Topic;

class UpdateTopic
{
    /**
     * Change the name and the description of a topic.
     *
     * @param  array{name: string, description?: string|null}  $data
     */
    public function handle(Topic $topic, array $data): Topic
    {
        $topic->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return $topic;
    }
}
