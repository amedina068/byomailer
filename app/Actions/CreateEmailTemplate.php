<?php

namespace App\Actions;

use App\Models\EmailTemplate;
use App\Models\User;

class CreateEmailTemplate
{
    /**
     * Create an email template in the client of the user. The user is the
     * author. The first template of a client becomes its default.
     */
    public function handle(User $user, string $name, string $htmlContent): EmailTemplate
    {
        $template = new EmailTemplate;
        $template->forceFill([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'name' => $name,
            'html_content' => $htmlContent,
        ])->save();

        $template->markDefaultIfNone();

        return $template;
    }
}
