<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\User;
use App\Services\UserMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

class UserMailerFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_default_mailer_when_ses_api_is_disabled(): void
    {
        config(['mail.use_ses_api' => false, 'mail.default' => 'log']);

        $user = User::factory()->create();

        $pendingMail = app(UserMailerFactory::class)->mailerFor($user);

        $reflection = new ReflectionProperty($pendingMail, 'mailer');
        $mailer = $reflection->getValue($pendingMail);

        $this->assertSame('log', $mailer->getSymfonyTransport()->__toString());
    }

    public function test_it_returns_ses_mailer_when_ses_api_is_enabled(): void
    {
        config(['mail.use_ses_api' => true]);

        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create([
            'region' => 'us-east-1',
        ]);

        $pendingMail = app(UserMailerFactory::class)->mailerFor($user);

        $reflection = new ReflectionProperty($pendingMail, 'mailer');
        $mailer = $reflection->getValue($pendingMail);

        $this->assertStringContainsString('ses', $mailer->getSymfonyTransport()->__toString());
    }
}
