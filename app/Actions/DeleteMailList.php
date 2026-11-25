<?php

namespace App\Actions;

use App\Models\MailList;
use App\Support\AudienceUsage;
use Illuminate\Validation\ValidationException;

class DeleteMailList
{
    /**
     * Delete a list, its topics and its memberships. The contacts stay.
     * A list that newsletters, sequences, segments or automations use is
     * not deleted, because their audience changes without the list.
     *
     * @throws ValidationException
     */
    public function handle(MailList $list): void
    {
        $usage = AudienceUsage::ofList($list);

        if (AudienceUsage::isInUse($usage)) {
            throw ValidationException::withMessages([
                'list' => 'These items use the list or one of its topics: '.AudienceUsage::describe($usage).'. Remove the list and its topics from them, then delete the list.',
            ]);
        }

        $list->delete();
    }
}
