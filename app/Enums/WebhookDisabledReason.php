<?php

namespace App\Enums;

enum WebhookDisabledReason: string
{
    case Manual = 'manual';
    case AutoFailures = 'auto_failures';
}
