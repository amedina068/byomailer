<?php

namespace App\Actions;

use App\Exceptions\TestMailException;
use App\Mail\NewsletterMail;
use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\Message;
use App\Models\TestMail;
use App\Models\User;
use App\Services\UserMailerFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

abstract class TestMailSender
{
    public function __construct(protected readonly UserMailerFactory $userMailerFactory) {}

    /**
     * Send a one-off copy of a message to the user's own address.
     *
     * @param  Model  $mailable  The newsletter or sequence step the test copy is built from.
     * @param  array<string, mixed>  $contentVariableDefaults
     *
     * @throws TestMailException
     */
    protected function dispatchTestMail(
        User $user,
        Model $mailable,
        Message $message,
        EmailIdentity $emailIdentity,
        array $contentVariableDefaults = [],
    ): ContactMail {
        $contact = Contact::withoutGlobalScopes()->firstOrCreate(
            ['email' => $user->email, 'client_id' => $user->client_id],
            ['user_id' => $user->id, 'name' => $user->name],
        );

        $testMail = TestMail::create([
            'client_id' => $user->client_id,
            'user_id' => $user->id,
            'mailable_type' => $mailable::class,
            'mailable_id' => $mailable->id,
            'sent_at' => now(),
        ]);

        $contactMail = ContactMail::create([
            'contact_id' => $contact->id,
            'client_id' => $user->client_id,
            'message_id' => $message->id,
            'subject' => '[Test] '.$message->subject,
            'from_email' => $emailIdentity->email,
            'from_name' => $emailIdentity->name,
            'template_data' => [],
            'mailable_type' => TestMail::class,
            'mailable_id' => $testMail->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $templateData = $message->resolveTemplateData(
            $contact,
            $contactMail,
            $contentVariableDefaults,
            $message->globalAttributeDefaults($user->client_id),
        );

        $contactMail->forceFill(['template_data' => $templateData])->save();

        $contactMail->setRelation('mailable', $testMail);
        $testMail->setRelation('mailable', $mailable);
        $contactMail->setRelation('contact', $contact);

        try {
            $this->userMailerFactory->mailerFor($user)
                ->to($user->email, $user->name)
                ->send(new NewsletterMail($contactMail));
        } catch (Throwable $e) {
            report($e);

            $contactMail->update(['status' => 'failed']);

            throw TestMailException::sendFailed($e);
        }

        return $contactMail;
    }
}
