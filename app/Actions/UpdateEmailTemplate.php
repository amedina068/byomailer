<?php

namespace App\Actions;

use App\Models\EmailTemplate;

class UpdateEmailTemplate
{
    /**
     * Change the name and the HTML of an email template. Messages that are
     * not sent yet use the new HTML at send time.
     *
     * @param  array{name?: string, html_content?: string}  $changes
     */
    public function handle(EmailTemplate $template, array $changes): void
    {
        $template->update(array_intersect_key($changes, array_flip(['name', 'html_content'])));
    }
}
