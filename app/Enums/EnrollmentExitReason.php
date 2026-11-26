<?php

namespace App\Enums;

/**
 * Why a contact left a visual automation before its last step. A contact
 * that reaches the end of the workflow has no exit reason.
 */
enum EnrollmentExitReason: string
{
    case Goal = 'goal';
    case ExitCondition = 'exit_condition';
    case Unsubscribed = 'unsubscribed';
}
