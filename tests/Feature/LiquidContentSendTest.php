<?php

namespace Tests\Feature;

use App\Actions\SendSequenceStepTestMail;
use App\Jobs\SendNewsletterChunkJob;
use App\Jobs\SendOptInEmailJob;
use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\MailList;
use App\Models\Newsletter;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\User;
use App\Services\EmailSendingGate;
use App\Services\NewsletterTransport;
use App\Services\SesClientManager;
use App\Services\UserMailerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class LiquidContentSendTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private User $user;

    private EmailIdentity $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->client->forceFill(['postal_address' => '1 Main St, Springfield'])->save();
        $this->identity = EmailIdentity::factory()->for($this->user)->create(['configuration_set_name' => 'cs-liquid']);

        config(['mail.use_ses_api' => false]);
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }

    public function test_a_newsletter_renders_conditions_loops_and_filters(): void
    {
        $this->actingAsTenant($this->user->client_id);
        Block::factory()->for($this->user)->create(['name' => 'footer', 'html_content' => '<p>{% if vip == "yes" %}Thanks, VIP{% endif %}</p>']);

        $newsletter = $this->newsletter(
            subject: '{{ name | upcase }}, news',
            html: '<p>Hi {{ name }}!</p><ul>{% for item in interests %}<li>{{ item }}</li>{% endfor %}</ul>'
                .'<p>{{ nickname | default: "friend" }} · {{ postal_address }} · <a href="{{ unsubscribe_url }}">Leave</a></p>{{ block.footer }}',
            preview: 'Picked for {{ name }}',
        );
        $contact = $this->contact(['name' => 'Ann <b>', 'extra_attributes' => ['vip' => 'yes', 'interests' => ['email', 'sms']]]);

        $this->runChunk($newsletter, [$contact]);

        $email = $this->sentEmails()->sole();
        $html = (string) $email->getHtmlBody();
        $unsubscribe = ContactMail::query()->sole()->unsubscribe_link;

        $this->assertSame('ANN <B>, news', $email->getSubject());
        $this->assertStringContainsString('<p>Hi Ann &lt;b&gt;!</p><ul><li>email</li><li>sms</li></ul>', $html);
        $this->assertStringContainsString('friend · 1 Main St, Springfield · <a href="'.$unsubscribe.'">Leave</a>', $html);
        $this->assertStringContainsString('<p>Thanks, VIP</p>', $html);
        $this->assertStringContainsString('Picked for Ann &lt;b&gt;', $html);
        $this->assertStringContainsString('Thanks, VIP', (string) $email->getTextBody());
        $this->assertStringNotContainsString('{%', (string) $email->getTextBody());
    }

    public function test_defaults_fill_liquid_variables(): void
    {
        $this->actingAsTenant($this->user->client_id);
        ContactAttribute::factory()->for($this->user)->create(['name' => 'company', 'default_value' => 'Acme']);

        $newsletter = $this->newsletter(subject: 'Hi', html: '<p>{% if company %}{{ company }}{% endif %} in {{ city | upcase }}</p>');
        $newsletter->update(['content_variables' => ['city' => 'your city']]);

        $this->runChunk($newsletter, [$this->contact(['extra_attributes' => ['company' => '']])]);

        $this->assertStringContainsString('<p>Acme in YOUR CITY</p>', (string) $this->sentEmails()->sole()->getHtmlBody());
    }

    public function test_content_that_is_not_valid_liquid_sends_as_before(): void
    {
        $this->actingAsTenant($this->user->client_id);

        $newsletter = $this->newsletter(subject: 'Hi {{ name }}', html: '<p>{% if vip %}VIP {{ name }}</p>');

        $this->runChunk($newsletter, [$this->contact(['name' => 'Ann'])]);

        $email = $this->sentEmails()->sole();
        $this->assertFalse($newsletter->message->usesLiquid());
        $this->assertSame('Hi Ann', $email->getSubject());
        $this->assertStringContainsString('<p>{% if vip %}VIP Ann</p>', (string) $email->getHtmlBody());
    }

    public function test_a_liquid_newsletter_sends_one_email_per_contact_through_the_ses_api(): void
    {
        config(['mail.use_ses_api' => true]);
        $this->actingAsTenant($this->user->client_id);

        $clients = Mockery::mock(SesClientManager::class);
        $clients->shouldNotReceive('v2ClientFor');
        $this->app->instance(SesClientManager::class, $clients);
        $this->mailerSendsToTheArrayTransport();

        $newsletter = $this->newsletter(subject: 'Hi', html: '<p>{% if vip %}VIP{% else %}Hello{% endif %} {{ name }}</p>');
        $contacts = [$this->contact(['name' => 'Ann', 'extra_attributes' => ['vip' => 'yes']]), $this->contact(['name' => 'Bob'])];

        $templateName = app(NewsletterTransport::class)->createTemplate($newsletter, $this->user, $newsletter->message);
        $this->runChunk($newsletter, $contacts, $templateName);

        $this->assertNull($templateName);
        $this->assertSame(
            ['<p>VIP Ann</p>', '<p>Hello Bob</p>'],
            $this->sentEmails()->map(fn (Email $email): string => trim(strip_tags((string) $email->getHtmlBody(), '<p>')))->all(),
        );
        $this->assertSame(['sent', 'sent'], ContactMail::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_a_contact_whose_data_breaks_the_liquid_code_fails_alone(): void
    {
        $this->actingAsTenant($this->user->client_id);

        $newsletter = $this->newsletter(subject: 'Hi', html: '<p>{{ 10 | divided_by: count }}</p>');
        $broken = $this->contact(['extra_attributes' => ['count' => '0']]);
        $good = $this->contact(['extra_attributes' => ['count' => '2']]);

        $this->runChunk($newsletter, [$broken, $good]);

        $this->assertStringContainsString('<p>5</p>', (string) $this->sentEmails()->sole()->getHtmlBody());
        $this->assertSame('failed', ContactMail::query()->where('contact_id', $broken->id)->value('status'));
        $this->assertStringContainsString('divided by 0', (string) ContactMail::query()->where('contact_id', $broken->id)->value('failure_reason'));
        $this->assertSame('sent', ContactMail::query()->where('contact_id', $good->id)->value('status'));
    }

    public function test_a_sequence_step_test_mail_renders_liquid(): void
    {
        $this->mailerSendsToTheArrayTransport();
        $this->actingAsTenant($this->user->client_id);

        $sequence = Sequence::factory()->for($this->user)->create(['content_variables' => ['plan' => 'Free']]);
        $step = SequenceStep::factory()->for($sequence)->create();
        $step->message()->update([
            'email_identity_id' => $this->identity->id,
            'subject' => 'Your {{ plan | downcase }} plan',
            'html_content' => '<p>{% case plan %}{% when "Free" %}Upgrade today{% else %}Thanks{% endcase %}</p>',
            'text_content' => null,
        ]);

        app(SendSequenceStepTestMail::class)->handle($this->user, $step->fresh());

        $email = $this->sentEmails()->sole();
        $this->assertSame('[Test] Your free plan', $email->getSubject());
        $this->assertStringContainsString('<p>Upgrade today</p>', (string) $email->getHtmlBody());
    }

    public function test_an_opt_in_email_renders_liquid_with_the_opt_in_link(): void
    {
        $this->actingAsTenant($this->user->client_id);
        $list = MailList::factory()->for($this->user)->create();
        $list->message()->updateOrCreate([], [
            'client_id' => $this->user->client_id,
            'user_id' => $this->user->id,
            'email_identity_id' => $this->identity->id,
            'from_email' => $this->identity->email,
            'subject' => 'Confirm, {{ name | default: "friend" }}',
            'html_content' => '<p>{% if name %}Hi {{ name }}{% endif %} <a href="{{ opt_in_url }}">Confirm</a></p>',
            'markdown_content' => '',
            'text_content' => null,
        ]);
        $contact = Contact::factory()->for($this->user)->create(['name' => 'Ann']);

        (new SendOptInEmailJob($contact, $list->fresh()))->handle(app(UserMailerFactory::class), app(EmailSendingGate::class));

        $email = $this->sentEmails()->sole();
        $optInLink = ContactMail::withoutGlobalScopes()->sole()->opt_in_link;

        $this->assertSame('Confirm, Ann', $email->getSubject());
        $this->assertStringContainsString('<p>Hi Ann <a href="'.e($optInLink).'">Confirm</a></p>', (string) $email->getHtmlBody());
    }

    private function newsletter(string $subject, string $html, ?string $preview = null): Newsletter
    {
        $newsletter = Newsletter::factory()->for($this->user)->create(['status' => 'queued']);
        $newsletter->message()->update([
            'email_identity_id' => $this->identity->id,
            'from_email' => $this->identity->email,
            'from_name' => 'Liquid Sender',
            'subject' => $subject,
            'preview_text' => $preview,
            'html_content' => $html,
            'text_content' => null,
        ]);

        return $newsletter->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->for($this->user)->create($attributes);
    }

    /**
     * @param  list<Contact>  $contacts
     */
    private function runChunk(Newsletter $newsletter, array $contacts, ?string $templateName = null): void
    {
        $message = $newsletter->message->fresh();

        (new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $this->user,
            fromEmail: $message->from_email,
            fromName: $message->from_name,
            contactIds: collect($contacts)->pluck('id')->all(),
            templateName: $templateName,
            message: $message,
            tenantName: null,
        ))->handle(app(NewsletterTransport::class));
    }

    private function mailerSendsToTheArrayTransport(): void
    {
        $factory = Mockery::mock(UserMailerFactory::class);
        $factory->shouldReceive('mailerFor')->andReturnUsing(fn (): PendingMail => new PendingMail(Mail::mailer('array')));
        $this->app->instance(UserMailerFactory::class, $factory);
    }

    /**
     * @return Collection<int, Email>
     */
    private function sentEmails(): Collection
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $sent): Email => $sent->getOriginalMessage())
            ->values();
    }
}
