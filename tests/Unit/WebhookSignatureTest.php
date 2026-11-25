<?php

namespace Tests\Unit;

use App\Support\Webhooks\WebhookSignature;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function test_sign_matches_manual_hmac(): void
    {
        $timestamp = '1750000000';
        $body = '{"event":"contact.subscribed"}';
        $secret = 'whsec_testsecret';

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $this->assertSame($expected, WebhookSignature::sign($timestamp, $body, $secret));
    }

    public function test_sign_is_deterministic(): void
    {
        $a = WebhookSignature::sign('1750000000', 'body', 'secret');
        $b = WebhookSignature::sign('1750000000', 'body', 'secret');

        $this->assertSame($a, $b);
    }

    public function test_signature_changes_with_timestamp(): void
    {
        $a = WebhookSignature::sign('1750000000', 'body', 'secret');
        $b = WebhookSignature::sign('1750000001', 'body', 'secret');

        $this->assertNotSame($a, $b);
    }
}
