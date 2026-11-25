<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * BYOMailer could not get AWS credentials for an account. The message is safe
 * to show to the user. It never contains a key, a token or an AWS response.
 */
class AwsCredentialsException extends RuntimeException
{
    //
}
