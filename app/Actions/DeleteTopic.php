<?php

namespace App\Actions;

use App\Models\Topic;
use App\Support\AudienceUsage;
use Illuminate\Validation\ValidationException;

class DeleteTopic
{
    /**
     * Delete a topic and its subscriptions. A topic that newsletters,
     * sequences, segments or automations use is not deleted, because their
     * audience changes without the topic.
     *
     * @throws ValidationException
     */
    public function handle(Topic $topic): void
    {
        $usage = AudienceUsage::ofTopic($topic);

        if (AudienceUsage::isInUse($usage)) {
            throw ValidationException::withMessages([
                'topic' => 'These items use the topic: '.AudienceUsage::describe($usage).'. Remove the topic from them, then delete the topic.',
            ]);
        }

        $topic->delete();
    }
}
