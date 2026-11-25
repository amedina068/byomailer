<?php

namespace App\Enums;

enum DnsRecordPurpose: string
{
    case Dkim = 'dkim';
    case MailFrom = 'mail_from';
    case Dmarc = 'dmarc';
}
