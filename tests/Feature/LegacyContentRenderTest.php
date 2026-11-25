<?php

namespace Tests\Feature;

use App\Jobs\SendNewsletterChunkJob;
use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactMail;
use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\NewsletterTransport;
use App\Services\SesClientManager;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Content saved before the Liquid engine must send the same email after it.
 *
 * `tests/Fixtures/legacy-content-renders.json` holds the output of the send
 * code from before the Liquid change. The test sends the same content through
 * both send paths and compares each subject, body and data map with it.
 */
class LegacyContentRenderTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/legacy-content-renders.json';

    /**
     * @var array<string, mixed>
     */
    private array $sesCalls = [];

    public function test_current_content_sends_the_same_email_through_the_mailer(): void
    {
        $this->assertSame($this->fixture('mailer'), $this->renderAll(fn (array $case): array => $this->sendThroughMailer($case)));
    }

    public function test_current_content_sends_the_same_email_through_the_ses_bulk_api(): void
    {
        $this->assertSame($this->fixture('ses'), $this->renderAll(fn (array $case): array => $this->sendThroughSesBulk($case)));
    }

    /**
     * @return array<string, array{subject: string, preview?: string, html: string, text?: string|null, template?: string}>
     */
    private static function cases(): array
    {
        return [
            'bare placeholders' => [
                'subject' => 'News for {{ name }}',
                'preview' => 'Hello {{name}}, read this',
                'html' => '<p>Hi {{ name }}, from {{ company }} in {{ city_label }}.</p><p>Missing: [{{ nickname }}]</p>',
            ],
            'system variables' => [
                'subject' => 'Your update',
                'html' => '<p>{{ postal_address }}</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a> <a href="{{ preferences_url }}">Preferences</a></p>',
            ],
            'query string after a placeholder' => [
                'subject' => 'Cart',
                'html' => '<p><a href="{{ checkout_url }}?coupon=X">Buy</a> Are you {{ name }}? Great</p>',
            ],
            'utm on fixed and variable links' => [
                'subject' => 'Links for you',
                'html' => '<p><a href="https://shop.example.com/sale">Sale</a> <a href="{{ checkout_url }}">Cart</a> <a href="https://shop.example.com/?utm_source=keep">Keep</a></p>',
            ],
            'block with a placeholder' => [
                'subject' => 'Block {{ block.signature }}',
                'html' => '<p>Body</p>{{ block.footer }}',
            ],
            'dotted, hyphen and array attributes' => [
                'subject' => 'Hi {{ first-name }}',
                'html' => '<p>{{ first-name }} lives in {{ address.city }}. Likes {{ interests }}.</p>',
            ],
            'text that is not a placeholder' => [
                'subject' => 'Literal {{ first name }}',
                'html' => '<p>{{ first name }} {{#if vip}}VIP{{/if}} {{{ name }}} 100% {% off %} {{ name | upcase }}</p>',
            ],
            'stored text body' => [
                'subject' => 'Text',
                'html' => '<p>Hi {{ name }}</p>',
                'text' => "Hi {{ name }}\nVisit {{ checkout_url }}?coupon=X",
            ],
            'email template' => [
                'subject' => 'Templated',
                'html' => '<p>Slot for {{ name }}</p>',
                'template' => '<div class="wrap"><h1>{{ company }}</h1>{{ content }}<footer><a href="{{ unsubscribe_url }}">Leave</a></footer></div>',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $path): array
    {
        $fixture = json_decode((string) file_get_contents(self::FIXTURE), true, flags: JSON_THROW_ON_ERROR);

        return $fixture[$path];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $send
     * @return array<string, mixed>
     */
    private function renderAll(callable $send): array
    {
        return collect(self::cases())->map(fn (array $case): array => $send($case))->all();
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function sendThroughMailer(array $case): array
    {
        config(['mail.use_ses_api' => false]);
        Mail::mailer('array')->getSymfonyTransport()->flush();

        [$newsletter, $contact] = $this->newsletterFor($case);
        $this->runChunk($newsletter, $contact, null);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        /** @var SentMessage $sent */
        $sent = $messages->first();
        /** @var Email $email */
        $email = $sent->getOriginalMessage();

        return $this->normalize([
            'subject' => $email->getSubject(),
            'html' => $email->getHtmlBody(),
            'text' => $email->getTextBody(),
        ], $newsletter);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function sendThroughSesBulk(array $case): array
    {
        config(['mail.use_ses_api' => true]);
        $this->sesCalls = [];

        $client = Mockery::mock(SesV2Client::class);
        $client->shouldReceive('createEmailTemplate')->andReturnUsing(function (array $args): Result {
            $this->sesCalls['template'] = $args['TemplateContent'];

            return new Result([]);
        });
        $client->shouldReceive('sendBulkEmail')->andReturnUsing(function (array $payload): Result {
            $this->sesCalls['data'] = json_decode($payload['BulkEmailEntries'][0]['ReplacementEmailContent']['ReplacementTemplate']['ReplacementTemplateData'], true);

            return new Result(['BulkEmailEntryResults' => [['Status' => 'SUCCESS']]]);
        });

        $clients = Mockery::mock(SesClientManager::class);
        $clients->shouldReceive('v2ClientFor')->andReturn($client);
        $this->app->instance(SesClientManager::class, $clients);

        [$newsletter, $contact] = $this->newsletterFor($case);

        $templateName = app(NewsletterTransport::class)->createTemplate($newsletter, $newsletter->user, $newsletter->message);
        $this->runChunk($newsletter, $contact, $templateName);

        return $this->normalize([
            'subject' => $this->sesCalls['template']['Subject'],
            'html' => $this->sesCalls['template']['Html'],
            'text' => $this->sesCalls['template']['Text'],
            'data' => $this->sesCalls['data'],
        ], $newsletter);
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{0: Newsletter, 1: Contact}
     */
    private function newsletterFor(array $case): array
    {
        $user = User::factory()->create();
        $user->client->forceFill(['postal_address' => '1 Main St, Springfield'])->save();
        $this->actingAsTenant($user->client_id);

        $identity = EmailIdentity::factory()->for($user)->create(['configuration_set_name' => 'cs-golden']);
        ContactAttribute::factory()->for($user)->create(['name' => 'company', 'default_value' => 'Acme & Sons']);
        Block::factory()->for($user)->create(['name' => 'footer', 'html_content' => '<p>Bye {{ name }}, see <a href="https://example.com/blog">the blog</a></p>']);
        Block::factory()->for($user)->create(['name' => 'signature', 'html_content' => '<p>The <b>team</b></p>']);

        $template = isset($case['template'])
            ? EmailTemplate::factory()->for($user)->create(['html_content' => $case['template']])
            : null;

        $newsletter = Newsletter::factory()->for($user)->create([
            'status' => 'queued',
            'content_variables' => ['city_label' => 'your city', 'name' => 'Friend'],
        ]);
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Golden Sender',
            'subject' => $case['subject'],
            'preview_text' => $case['preview'] ?? null,
            'html_content' => $template ? '' : $case['html'],
            'text_content' => $case['text'] ?? null,
            'email_template_id' => $template?->id,
            'template_slot_content' => $template ? $case['html'] : null,
        ]);

        $contact = Contact::factory()->for($user)->create([
            'email' => 'ann@example.com',
            'name' => 'Ann <b>&</b> "Q"',
            'extra_attributes' => [
                'first-name' => 'Ann',
                'address' => ['city' => 'Paris'],
                'interests' => ['email', 'café'],
                'checkout_url' => 'https://shop.example.com/cart?a=1&b=2',
                'company' => '',
            ],
        ]);

        return [$newsletter->fresh(), $contact];
    }

    private function runChunk(Newsletter $newsletter, Contact $contact, ?string $templateName): void
    {
        $message = $newsletter->message->fresh();

        (new SendNewsletterChunkJob(
            newsletter: $newsletter,
            user: $newsletter->user,
            fromEmail: $message->from_email,
            fromName: $message->from_name,
            contactIds: [$contact->id],
            templateName: $templateName,
            message: $message,
            tenantName: null,
        ))->handle(app(NewsletterTransport::class));
    }

    /**
     * Replace the per-mail links with stable tokens. The rest of the output
     * must match byte for byte.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function normalize(array $output, Newsletter $newsletter): array
    {
        $contactMail = ContactMail::query()->where('mailable_id', $newsletter->id)->sole();

        $tokens = [
            $contactMail->unsubscribe_link => '{UNSUBSCRIBE_LINK}',
            $contactMail->preferences_link => '{PREFERENCES_LINK}',
            $contactMail->uuid => '{UUID}',
        ];

        array_walk_recursive($output, function (mixed &$value) use ($tokens): void {
            if (is_string($value)) {
                $value = strtr($value, $tokens);
            }
        });

        return $output;
    }
}
