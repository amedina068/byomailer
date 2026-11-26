<?php

namespace App\Exceptions;

use App\Models\EmailIdentity;
use RuntimeException;

class LinkDomainUnhealthyException extends RuntimeException
{
    public static function for(EmailIdentity $identity, string $reason): self
    {
        return new self(
            "Custom link domain {$identity->custom_link_domain} (email identity {$identity->id}) does not reach the app. {$reason} "
            .'One-click unsubscribe and the preference links on this domain do not work until a request through the domain reaches the app.'
        );
    }
}
