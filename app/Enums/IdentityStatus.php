<?php

namespace App\Enums;

enum IdentityStatus: string
{
    case Pending = 'pending';
    case Verifying = 'verifying';
    case Verified = 'verified';
    case Failed = 'failed';
}
