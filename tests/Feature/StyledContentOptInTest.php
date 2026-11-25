<?php

namespace Tests\Feature;

use App\Jobs\SendOptInEmailJob;
use App\Mail\OptInMail;
use App\Models\Contact;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\User;
use App\Services\EmailSendingGate;
use App\Services\UserMailerFactory;
use App\Support\Tenant;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class StyledContentOptInTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_opt_in_mail_render_keeps_inline_typography_styles(): void
    {
        $user = User::factory()->create();
        EmailCredential::factory()->for($user)->create();

        $list = MailList::factory()->for($user)->create(['opt_in_enabled' => true]);
        $identity = EmailIdentity::factory()->for($user)->create();

        $html = '<h1 style="font-family: Georgia, serif; font-size: 32px; color: #111827">Confirm</h1>'
            .'<p style="font-family: Arial, sans-serif; font-size: 16px; color: #1f2937">'
            .'<a href="{{opt_in_url}}" style="color: #c8ff00; font-weight: 700">Confirm now</a>'
            .'</p>';

        $list->message()->create([
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'email_identity_id' => $identity->id,
            'subject' => 'Confirm your subscription',
            'from_email' => $identity->email,
            'from_name' => $identity->name ?: $user->name,
            'html_content' => $html,
            'text_content' => 'plain',
            'markdown_content' => '',
        ]);

        $contact = Contact::factory()->for($user)->create();

        $captured = null;
        $mailManager = Mockery::mock(MailManager::class);
        $mailer = Mockery::mock(MailerContract::class);
        $mailManager->shouldReceive('build')->once()->andReturn($mailer);
        $mailer->shouldReceive('send')
            ->once()
            ->andReturnUsing(function (OptInMail $mail) use (&$captured): void {
                $captured = $mail;
            });
        $this->app->instance(MailManager::class, $mailManager);

        $job = new SendOptInEmailJob($contact, $list);
        $job->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));

        $this->assertNotNull($captured);
        // The mailer renders inside the job in production. The mock captured the mail, so render it as the client.
        $rendered = Tenant::run($user->client_id, fn (): string => $captured->render());

        $this->assertStringContainsString('font-family: Georgia', $rendered);
        $this->assertStringContainsString('font-size: 32px', $rendered);
        $this->assertStringContainsString('color: #111827', $rendered);
        $this->assertStringContainsString('color: #c8ff00', $rendered);
        $this->assertStringContainsString('font-weight: 700', $rendered);

        // {{opt_in_url}} was replaced with the opt-in link of the contact mail.
        $this->assertStringNotContainsString('{{opt_in_url}}', $rendered);
        $this->assertStringContainsString('href="'.$captured->contactMail->opt_in_link.'"', $rendered);
    }
}
