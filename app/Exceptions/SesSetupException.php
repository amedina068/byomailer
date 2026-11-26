<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * BYOMailer could not set up the SNS topic or the SES tenant in the AWS
 * account. The message is safe to show to the user.
 */
class SesSetupException extends RuntimeException
{
    //
}
