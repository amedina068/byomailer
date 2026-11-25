<?php

namespace App\Actions;

use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\User;
use App\Support\HtmlToText;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateMailList
{
    /**
     * Update the name and the opt-in settings of a list in one transaction.
     * The data has only the keys to change. When opt-in is on, the opt-in
     * email gets the subject, the content and the sender identity.
     *
     * @param  array{name?: string, opt_in_enabled?: bool, opt_in_subject?: string|null, opt_in_html_content?: string|null, opt_in_email_identity_id?: int|null}  $data
     */
    public function handle(User $user, MailList $list, array $data): MailList
    {
        DB::transaction(function () use ($user, $list, $data): void {
            $list->update(Arr::only($data, ['name', 'opt_in_enabled']));

            if ($data['opt_in_enabled'] ?? false) {
                $this->saveOptInMessage($user, $list, $data);
            }
        });

        return $list;
    }

    /**
     * @param  array{opt_in_subject?: string|null, opt_in_html_content?: string|null, opt_in_email_identity_id?: int|null}  $data
     */
    private function saveOptInMessage(User $user, MailList $list, array $data): void
    {
        $identity = EmailIdentity::query()->findOrFail($data['opt_in_email_identity_id'] ?? null);
        $htmlContent = (string) ($data['opt_in_html_content'] ?? '');

        $list->message()->updateOrCreate(
            ['messageable_type' => MailList::class, 'messageable_id' => $list->id],
            [
                'user_id' => $user->id,
                'client_id' => $user->client_id,
                'email_identity_id' => $identity->id,
                'subject' => $data['opt_in_subject'] ?? '',
                'from_email' => $identity->email,
                'from_name' => $identity->name ?: $user->name,
                'html_content' => $htmlContent,
                'text_content' => HtmlToText::convert($htmlContent),
                'markdown_content' => '',
            ],
        );
    }
}
