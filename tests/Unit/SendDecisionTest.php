<?php

namespace Tests\Unit;

use App\Services\EmailSendingGate;
use App\Services\SendDecision;
use PHPUnit\Framework\TestCase;

class SendDecisionTest extends TestCase
{
    public function test_ses_pause_uses_ses_account_key(): void
    {
        $decision = SendDecision::block(EmailSendingGate::SES_SENDING_DISABLED_MESSAGE, EmailSendingGate::SES_SENDING_DISABLED_REASON);

        $this->assertFalse($decision->allowed);
        $this->assertSame('ses_account', $decision->errorKey());
    }

    public function test_allow_has_no_reason(): void
    {
        $decision = SendDecision::allow();

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->reason);
        $this->assertNull($decision->message);
    }
}
