<?php

namespace App\Exceptions;

use RuntimeException;

class EmailIdentityProvisioningException extends RuntimeException
{
    public const REASON_NO_CREDENTIAL = 'no_credential';

    public const REASON_NO_CONFIGURATION_SET = 'no_configuration_set';

    public const REASON_AWS_FAILED = 'aws_failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function noCredential(): self
    {
        return new self(self::REASON_NO_CREDENTIAL, 'No AWS credentials found. Please complete the AWS onboarding first.');
    }

    public static function noConfigurationSet(): self
    {
        return new self(self::REASON_NO_CONFIGURATION_SET, 'No configuration set found for this identity.');
    }

    public static function awsFailed(string $message): self
    {
        return new self(self::REASON_AWS_FAILED, $message);
    }
}
