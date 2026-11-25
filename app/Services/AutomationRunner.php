<?php

namespace App\Services;

use App\Models\Automation;
use App\Models\Contact;

class AutomationRunner
{
    /**
     * Run automation actions for a single contact (assumes condition already satisfied).
     */
    public function runForContact(Automation $automation, Contact $contact): void
    {
        if ($contact->client_id !== $automation->client_id) {
            return;
        }

        $this->applyActions($contact, $automation);
    }

    private function applyActions(Contact $contact, Automation $automation): void
    {
        foreach ($automation->actions ?? [] as $action) {
            $this->applyAction($contact, (array) $action);
        }
    }

    private function applyAction(Contact $contact, array $action): void
    {
        $value = $action['value'] ?? null;

        if (! $value) {
            return;
        }

        switch ($action['type'] ?? '') {
            case 'add_tag':
                $contact->syncTags([$value], true);
                break;
            case 'remove_tag':
                $currentTagIds = $contact->tags()->pluck('tags.id')->all();
                $contact->syncTags(array_values(array_diff($currentTagIds, [$value])));
                break;
            case 'add_list':
                $contact->syncLists([$value], true);
                break;
            case 'remove_list':
                $currentListIds = $contact->lists()->pluck('lists.id')->all();
                $contact->syncLists(array_values(array_diff($currentListIds, [$value])));
                break;
            case 'add_sequence':
                $contact->syncSequences([$value], true);
                break;
            default:
                break;
        }
    }
}
