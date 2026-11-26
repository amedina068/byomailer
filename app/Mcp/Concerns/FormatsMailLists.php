<?php

namespace App\Mcp\Concerns;

use App\Models\MailList;
use App\Models\Topic;

trait FormatsMailLists
{
    /**
     * @return array{id: int, name: string, opt_in_enabled: bool, opt_in_email: array{subject: string, email_identity_id: int|null}|null}
     */
    protected function formatMailList(MailList $list): array
    {
        $message = $list->message;

        return [
            'id' => $list->id,
            'name' => $list->name,
            'opt_in_enabled' => (bool) $list->opt_in_enabled,
            'opt_in_email' => $message === null ? null : [
                'subject' => (string) $message->subject,
                'email_identity_id' => $message->email_identity_id,
            ],
        ];
    }

    /**
     * @return array{id: int, list_id: int, name: string, description: string|null}
     */
    protected function formatTopic(Topic $topic): array
    {
        return [
            'id' => $topic->id,
            'list_id' => $topic->list_id,
            'name' => $topic->name,
            'description' => $topic->description,
        ];
    }
}
