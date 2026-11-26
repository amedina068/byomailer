<?php

namespace App\Http\Requests\Concerns;

use App\Concerns\EmailTemplateValidationRules;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesEmailTemplate
{
    use EmailTemplateValidationRules;

    /**
     * Soft unsubscribe guard: saves without an unsubscribe link are rejected
     * unless the client explicitly confirms via `confirmed_no_unsubscribe=1`.
     */
    protected function enforceUnsubscribeGuard(Validator $validator): void
    {
        $error = $this->missingUnsubscribeLinkError(
            (string) $this->input('html_content', ''),
            $this->boolean('confirmed_no_unsubscribe'),
        );

        if ($error !== null) {
            $validator->errors()->add('html_content', $error);
        }
    }
}
