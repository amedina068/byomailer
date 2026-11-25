<?php

namespace App\Enums;

enum DkimStatus: string
{
    case NotStarted = 'NOT_STARTED';
    case Pending = 'PENDING';
    case Success = 'SUCCESS';
    case Failed = 'FAILED';
    case TemporaryFailure = 'TEMPORARY_FAILURE';
}
