<?php

namespace Tests\Feature;

use App\Mail\NewsletterMail;
use App\Models\EmailCredential;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class StyledContentSequenceStepTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_outbound_step_test_mail_keeps_inline_typography_styles(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user)->create();

        $sequence = Sequence::factory()->for($user)->create();
        $step = SequenceStep::factory()->for($sequence)->create();

        $html = '<h2 style="font-family: Georgia, serif; font-size: 24px; color: #111827">Welcome</h2>'
            .'<p style="font-family: Arial, sans-serif; font-size: 16px; color: #1f2937">'
            .'Body with <span style="color: #c8ff00">accent</span>.'
            .'</p>';

        $step->message()->update([
            'html_content' => $html,
            'text_content' => 'plain',
        ]);

        $captured = null;
        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (NewsletterMail $mail) use (&$captured): void {
                $captured = $mail;
            });
        $this->app->instance(MailManager::class, $mailManager);

        $this->actingAs($user)->post(route('sequences.steps.test-mail', [
            'sequence' => $sequence,
            'sequenceStep' => $step,
        ]));

        $this->assertNotNull($captured);
        $rendered = $captured->render();

        $this->assertStringContainsString('font-family: Georgia', $rendered);
        $this->assertStringContainsString('font-size: 24px', $rendered);
        $this->assertStringContainsString('color: #111827', $rendered);
        $this->assertStringContainsString('font-family: Arial, sans-serif', $rendered);
        $this->assertStringContainsString('font-size: 16px', $rendered);
        $this->assertStringContainsString('color: #c8ff00', $rendered);
    }
}
