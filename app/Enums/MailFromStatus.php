<?php

namespace App\Enums;

enum MailFromStatus: string
{
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case TemporaryFailure = 'TEMPORARY_FAILURE';
}
