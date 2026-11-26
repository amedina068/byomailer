<?php

namespace Tests\Feature;

use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\Newsletter;
use App\Models\User;
use App\Services\SesBulkEmailService;
use App\Services\SesClientManager;
use App\Services\SesCredentials;
use App\Services\SesTenantService;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class SesBulkEmailServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_it_sends_bulk_email_with_unsubscribe_headers(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user, 'user')->create([
            'type' => 'ses',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-1',
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => $identity->id]);
        $message = $newsletter->message->fresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('sendBulkEmail')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->with(Mockery::on(fn ($u) => $u->is($user)))
            ->andReturn($mockV2Client);
        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));

        $destinations = [
            [
                'email' => 'john@example.com',
                'name' => 'John Doe',
                'data' => ['first_name' => 'John'],
                'tags' => [['Name' => 'contact_mail_id', 'Value' => '123']],
                'unsubscribe_url' => 'https://example.com/unsubscribe/abc123',
                'one_click_unsubscribe_url' => 'https://example.com/one-click-unsubscribe/abc123',
            ],
            [
                'email' => 'jane@example.com',
                'name' => 'Jane Doe',
                'data' => ['first_name' => 'Jane'],
                'tags' => [['Name' => 'contact_mail_id', 'Value' => '456']],
                'unsubscribe_url' => 'https://example.com/unsubscribe/def456',
                'one_click_unsubscribe_url' => 'https://example.com/one-click-unsubscribe/def456',
            ],
        ];

        $service->sendBulkTemplatedEmail(
            'test-template',
            'sender@example.com',
            'Test Sender',
            $destinations,
            $user,
            [],
            'byomailer',
            $message,
        );

        $this->assertNotNull($capturedPayload);
        $this->assertSame('Test Sender <sender@example.com>', $capturedPayload['FromEmailAddress']);
        $this->assertSame('test-template', $capturedPayload['DefaultContent']['Template']['TemplateName']);
        $this->assertCount(2, $capturedPayload['BulkEmailEntries']);

        $firstEntry = $capturedPayload['BulkEmailEntries'][0];
        $this->assertSame(['john@example.com'], $firstEntry['Destination']['ToAddresses']);
        $this->assertArrayHasKey('ReplacementEmailContent', $firstEntry);
        $this->assertArrayHasKey('ReplacementTemplate', $firstEntry['ReplacementEmailContent']);
        $this->assertArrayHasKey('ReplacementTemplateData', $firstEntry['ReplacementEmailContent']['ReplacementTemplate']);
        $this->assertArrayHasKey('ReplacementTags', $firstEntry);
        $this->assertArrayHasKey('ReplacementHeaders', $firstEntry);
        $this->assertCount(2, $firstEntry['ReplacementHeaders']);

        $listUnsubscribeHeader = collect($firstEntry['ReplacementHeaders'])
            ->firstWhere('Name', 'List-Unsubscribe');
        $this->assertNotNull($listUnsubscribeHeader);
        $this->assertSame('<https://example.com/one-click-unsubscribe/abc123>', $listUnsubscribeHeader['Value']);

        $listUnsubscribePostHeader = collect($firstEntry['ReplacementHeaders'])
            ->firstWhere('Name', 'List-Unsubscribe-Post');
        $this->assertNotNull($listUnsubscribePostHeader);
        $this->assertSame('List-Unsubscribe=One-Click', $listUnsubscribePostHeader['Value']);

        // Check second destination has different unsubscribe URL
        $secondEntry = $capturedPayload['BulkEmailEntries'][1];
        $this->assertSame(['jane@example.com'], $secondEntry['Destination']['ToAddresses']);

        $secondListUnsubscribeHeader = collect($secondEntry['ReplacementHeaders'])
            ->firstWhere('Name', 'List-Unsubscribe');
        $this->assertSame('<https://example.com/one-click-unsubscribe/def456>', $secondListUnsubscribeHeader['Value']);
    }

    public function test_it_does_not_add_headers_when_unsubscribe_url_is_missing(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user, 'user')->create([
            'type' => 'ses',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-1',
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => $identity->id]);
        $message = $newsletter->message->fresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('sendBulkEmail')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->andReturn($mockV2Client);
        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));

        $destinations = [
            [
                'email' => 'john@example.com',
                'name' => 'John Doe',
                'data' => ['first_name' => 'John'],
                'tags' => [],
            ],
        ];

        $service->sendBulkTemplatedEmail(
            'test-template',
            'sender@example.com',
            null,
            $destinations,
            $user,
            [],
            'byomailer',
            $message,
        );

        $this->assertNotNull($capturedPayload);
        $this->assertSame('sender@example.com', $capturedPayload['FromEmailAddress']);
        $this->assertArrayHasKey('ReplacementEmailContent', $capturedPayload['BulkEmailEntries'][0]);
        $this->assertArrayNotHasKey('ReplacementHeaders', $capturedPayload['BulkEmailEntries'][0]);
    }

    public function test_it_includes_configuration_set_name_from_message_email_identity(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        EmailCredential::factory()->for($user, 'user')->create([
            'type' => 'ses',
            'region' => 'us-east-1',
        ]);

        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'my-config-set',
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => $identity->id]);
        $message = $newsletter->message->fresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('sendBulkEmail')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));

        $destinations = [
            [
                'email' => 'john@example.com',
                'data' => [],
                'tags' => [],
                'unsubscribe_url' => 'https://example.com/unsubscribe/abc',
            ],
        ];

        $service->sendBulkTemplatedEmail(
            'test-template',
            'sender@example.com',
            'Sender',
            $destinations,
            $user,
            [],
            'byomailer',
            $message,
        );

        $this->assertNotNull($capturedPayload);
        $this->assertSame('my-config-set', $capturedPayload['ConfigurationSetName']);
    }

    public function test_it_does_not_send_when_destinations_are_empty(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-1',
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => $identity->id]);
        $message = $newsletter->message->fresh();

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldNotReceive('v2ClientFor');

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));

        $service->sendBulkTemplatedEmail(
            'test-template',
            'sender@example.com',
            'Sender',
            [],
            $user,
            [],
            'byomailer',
            $message,
        );

        $this->assertTrue(true);
    }

    public function test_it_adds_utm_params_to_newsletter_template_html(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Weekly Digest',
            'html_content' => '<p>Hello! Visit <a href="https://example.com/guide">our guide</a>.</p>',
        ]);
        $newsletter->refresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $service->createNewsletterTemplate($newsletter, $user);

        $this->assertNotNull($capturedPayload);

        $html = $capturedPayload['TemplateContent']['Html'];

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $links = $document->getElementsByTagName('a');
        $guideLink = null;

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (str_contains($href, 'example.com/guide')) {
                $guideLink = $href;
            }
        }

        $this->assertNotNull($guideLink);

        $parts = parse_url($guideLink);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('mail', $query['utm_medium']);
        $this->assertSame(Str::slug('Weekly Digest'), $query['utm_source']);
        $this->assertSame('newsletter', $query['utm_campaign']);
    }

    public function test_it_does_not_override_existing_utm_params_in_newsletter_template(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Weekly Digest',
            'html_content' => '<a href="https://example.com?utm_source=custom&utm_medium=email">Link</a>',
        ]);
        $newsletter->refresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $service->createNewsletterTemplate($newsletter, $user);

        $html = $capturedPayload['TemplateContent']['Html'];

        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        $href = $document->getElementsByTagName('a')->item(0)?->getAttribute('href');
        $parts = parse_url($href);
        parse_str($parts['query'] ?? '', $query);

        $this->assertSame('custom', $query['utm_source']);
        $this->assertSame('email', $query['utm_medium']);
        $this->assertSame('newsletter', $query['utm_campaign']);
    }

    public function test_it_does_not_add_utm_params_to_unsubscribe_template_variable(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Weekly Digest',
            'html_content' => '<a href="{{unsubscribe_url}}">Unsubscribe</a>',
        ]);
        $newsletter->refresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $service->createNewsletterTemplate($newsletter, $user);

        $html = $capturedPayload['TemplateContent']['Html'];

        // The unsubscribe template variable should not have UTM params.
        // The buildHtmlTemplate wraps content in a full HTML doc and adds its own
        // unsubscribe link, so we check the original content link is preserved.
        $this->assertStringNotContainsString('utm_medium', explode('{{unsubscribe_url}}', $html)[0] ?? '');
    }

    public function test_it_preserves_spaced_handlebars_placeholders_inside_hrefs(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Weekly Digest',
            'html_content' => '<a href="{{ unsubscribe_url }}">Unsubscribe</a> · <a href="{{ preferences_url }}">Preferences</a>',
        ]);
        $newsletter->refresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $service->createNewsletterTemplate($newsletter, $user);

        $html = $capturedPayload['TemplateContent']['Html'];

        $this->assertStringContainsString('{{ unsubscribe_url }}', $html);
        $this->assertStringContainsString('{{ preferences_url }}', $html);
        $this->assertStringNotContainsString('%20unsubscribe_url%20', $html);
        $this->assertStringNotContainsString('%20preferences_url%20', $html);
        $this->assertStringNotContainsString('%7B%7B', $html);
        $this->assertStringNotContainsString('%7D%7D', $html);
    }

    public function test_it_keeps_every_variable_intact_across_the_full_template_body(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create();

        // Full body: header logo (src var), content-slot text + an embedded
        // var inside a real link's path, and a footer with unsubscribe /
        // preferences links. SES substitutes these per recipient, so they must
        // survive template creation un-mangled.
        $body = <<<'HTML'
        <header><img src="{{ logo_url }}" alt="logo"></header>
        <div data-slot="content">
          <p>Hi {{ name }}, latest from {{ company }}.</p>
          <p><a href="https://blog.test/post/{{ slug }}">Read the post</a></p>
        </div>
        <footer>
          <a href="{{ unsubscribe_url }}">Unsubscribe</a> ·
          <a href="{{ preferences_url }}">Manage preferences</a>
        </footer>
        HTML;

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Weekly Digest',
            'html_content' => $body,
        ]);
        $newsletter->refresh();

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->withArgs(function (array $payload) use (&$capturedPayload): bool {
                $capturedPayload = $payload;

                return true;
            })
            ->andReturn(new Result([]));

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')
            ->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $service->createNewsletterTemplate($newsletter, $user);

        $html = $capturedPayload['TemplateContent']['Html'];

        // Every variable survives — in src, in body text, embedded in a URL
        // path, and in the footer links.
        foreach (['{{ logo_url }}', '{{ name }}', '{{ company }}', '{{ slug }}', '{{ unsubscribe_url }}', '{{ preferences_url }}'] as $placeholder) {
            $this->assertStringContainsString($placeholder, $html);
        }

        // Nothing URL-encoded anywhere in the body.
        $this->assertStringNotContainsString('%20', $html);
        $this->assertStringNotContainsString('%7B', $html);
        $this->assertStringNotContainsString('%7D', $html);

        // The real link still receives UTM params; placeholders never do.
        $this->assertStringContainsString('blog.test/post/{{ slug }}', $html);
        $this->assertStringNotContainsString('{{ slug }}?utm', $html);
    }

    public function test_it_sets_reply_to_addresses_from_message_identity(): void
    {
        $cases = [
            ['support@example.com', 'Support Team', 'Support Team <support@example.com>'],
            ['support@example.com', 'Doe, John', '"Doe, John" <support@example.com>'],
            ['support@example.com', 'Café', '=?UTF-8?B?'.base64_encode('Café').'?= <support@example.com>'],
            ['support@example.com', null, 'support@example.com'],
        ];

        foreach ($cases as [$email, $name, $expected]) {
            $payload = $this->sendWithIdentity(['reply_to_email' => $email, 'reply_to_name' => $name]);

            $this->assertSame([$expected], $payload['ReplyToAddresses']);
        }
    }

    public function test_it_formats_from_name_as_a_safe_mailbox(): void
    {
        $cases = [
            ['Doe, John', '"Doe, John" <sender@example.com>'],
            ['Café', '=?UTF-8?B?'.base64_encode('Café').'?= <sender@example.com>'],
        ];

        foreach ($cases as [$fromName, $expected]) {
            $payload = $this->sendWithIdentity([], $fromName);

            $this->assertSame($expected, $payload['FromEmailAddress']);
        }
    }

    public function test_it_omits_reply_to_addresses_when_identity_has_none(): void
    {
        $payload = $this->sendWithIdentity(['reply_to_email' => null, 'reply_to_name' => 'Ignored']);

        $this->assertArrayNotHasKey('ReplyToAddresses', $payload);
    }

    public function test_template_joins_query_string_after_placeholder_with_a_per_contact_separator(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create(['configuration_set_name' => 'cs-1']);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Sale',
            'html_content' => '<p><a href="{{ checkout_url }}?coupon=KEEPIT57">Buy</a> Are you {{ name }}? Yes.</p>',
            'text_content' => "Buy: {{ checkout_url }}?coupon=KEEPIT57\nHi {{ name }}",
        ]);
        $newsletter->refresh();
        $message = $newsletter->message;

        $capturedTemplate = null;
        $capturedBulk = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedTemplate): Result {
                $capturedTemplate = $payload['TemplateContent'];

                return new Result([]);
            });
        $mockV2Client->shouldReceive('sendBulkEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedBulk): Result {
                $capturedBulk = $payload;

                return new Result([]);
            });

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $templateName = $service->createNewsletterTemplate($newsletter, $user);

        $this->assertStringContainsString('href="{{{ checkout_url__utm }}}{{{ checkout_url__utm__qs }}}coupon=KEEPIT57"', $capturedTemplate['Html']);
        $this->assertStringContainsString('Are you {{ name }}? Yes.', $capturedTemplate['Html']);
        $this->assertSame("Buy: {{{ checkout_url }}}{{{ checkout_url__qs }}}coupon=KEEPIT57\nHi {{{ name }}}", $capturedTemplate['Text']);

        $service->sendBulkTemplatedEmail($templateName, $identity->email, 'Test Sender', [
            ['email' => 'plain@example.com', 'data' => ['checkout_url' => 'https://shop.test/checkout'], 'tags' => []],
            ['email' => 'query@example.com', 'data' => ['checkout_url' => 'https://shop.test/checkout?token=abc'], 'tags' => []],
        ], $user, ['checkout_url' => 'https://shop.test/checkout?ref=default'], null, $message);

        $entryData = array_map(
            fn (array $entry): array => json_decode($entry['ReplacementEmailContent']['ReplacementTemplate']['ReplacementTemplateData'], true),
            $capturedBulk['BulkEmailEntries'],
        );
        $defaultData = json_decode($capturedBulk['DefaultContent']['Template']['TemplateData'], true);

        $this->assertSame('?', $entryData[0]['checkout_url__qs']);
        $this->assertSame('&', $entryData[1]['checkout_url__qs']);
        $this->assertSame('&', $defaultData['checkout_url__qs']);
        $this->assertSame('&', $entryData[0]['checkout_url__utm__qs']);
        $this->assertSame('&', $defaultData['checkout_url__utm__qs']);
    }

    public function test_variable_links_get_utm_parameters_from_the_per_recipient_data(): void
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create(['configuration_set_name' => 'cs-1']);

        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update([
            'email_identity_id' => $identity->id,
            'from_email' => $identity->email,
            'from_name' => 'Test Sender',
            'subject' => 'Lifetime Offer',
            'html_content' => '<p><a href="{{ checkout_url }}">Plain</a>'
                .' <a href="{{ checkout_url }}?coupon=LVFOREVER">Coupon</a></p>'
                .'<footer><a href="{{ unsubscribe_url }}">Unsubscribe</a> <a href="{{ preferences_url }}">Preferences</a></footer>',
        ]);
        $newsletter->refresh();
        $message = $newsletter->message;

        $capturedTemplate = null;
        $capturedBulk = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('createEmailTemplate')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedTemplate): Result {
                $capturedTemplate = $payload['TemplateContent'];

                return new Result([]);
            });
        $mockV2Client->shouldReceive('sendBulkEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedBulk): Result {
                $capturedBulk = $payload;

                return new Result([]);
            });

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $templateName = $service->createNewsletterTemplate($newsletter, $user);

        $unsubscribeUrl = 'https://mail.example.com/unsubscribe/abc';
        $preferencesUrl = 'https://mail.example.com/preferences/abc?signature=sig1';

        $service->sendBulkTemplatedEmail($templateName, $identity->email, 'Test Sender', [
            ['email' => 'plain@example.com', 'tags' => [], 'unsubscribe_url' => $unsubscribeUrl, 'data' => [
                'checkout_url' => 'https://shop.test/checkout?token=abc',
                'preferences_url' => $preferencesUrl,
            ]],
            ['email' => 'tagged@example.com', 'tags' => [], 'unsubscribe_url' => $unsubscribeUrl, 'data' => [
                'checkout_url' => 'https://shop.test/checkout?utm_source=partner',
                'preferences_url' => $preferencesUrl,
            ]],
            ['email' => 'signed@example.com', 'tags' => [], 'unsubscribe_url' => $unsubscribeUrl, 'data' => [
                'checkout_url' => 'https://shop.test/offer?expires=1700000000&signature=abc123',
                'preferences_url' => $preferencesUrl,
            ]],
        ], $user, [], null, $message);

        $hrefs = array_map(
            fn (array $entry): array => $this->renderedHrefs(
                $capturedTemplate['Html'],
                json_decode($entry['ReplacementEmailContent']['ReplacementTemplate']['ReplacementTemplateData'], true),
            ),
            $capturedBulk['BulkEmailEntries'],
        );

        $utm = 'utm_medium=mail&utm_source=lifetime-offer&utm_campaign=newsletter';

        $this->assertSame([
            'https://shop.test/checkout?'.$utm.'&token=abc',
            'https://shop.test/checkout?'.$utm.'&token=abc&coupon=LVFOREVER',
            $unsubscribeUrl,
            $preferencesUrl,
        ], $hrefs[0]);

        $this->assertSame(
            'https://shop.test/checkout?utm_medium=mail&utm_source=partner&utm_campaign=newsletter&coupon=LVFOREVER',
            $hrefs[1][1],
        );

        $this->assertSame([
            'https://shop.test/offer?expires=1700000000&signature=abc123',
            'https://shop.test/offer?expires=1700000000&signature=abc123&coupon=LVFOREVER',
            $unsubscribeUrl,
            $preferencesUrl,
        ], $hrefs[2]);
    }

    /**
     * Fill the placeholders of a template the way SES does, and list the hrefs.
     *
     * @param  array<string, string>  $data
     * @return list<string>
     */
    private function renderedHrefs(string $template, array $data): array
    {
        $html = preg_replace_callback(
            '/\{\{(\{?)\s*([\w.]+)\s*\}?\}\}/',
            fn (array $match): string => $match[1] === '{' ? (string) ($data[$match[2]] ?? '') : e((string) ($data[$match[2]] ?? '')),
            $template,
        );

        preg_match_all('/<a[^>]*\shref="([^"]*)"/', (string) $html, $matches);

        return array_map(fn (string $href): string => html_entity_decode($href), $matches[1]);
    }

    /**
     * @param  array<string, mixed>  $identityAttributes
     * @return array<string, mixed>
     */
    private function sendWithIdentity(array $identityAttributes, string $fromName = 'Test Sender'): array
    {
        $user = User::factory()->create();
        $this->actingAsTenant($user->client_id);
        $identity = EmailIdentity::factory()->for($user)->create([
            'configuration_set_name' => 'cs-1',
            ...$identityAttributes,
        ]);
        $newsletter = Newsletter::factory()->for($user)->create();
        $newsletter->message()->update(['email_identity_id' => $identity->id]);

        $capturedPayload = null;

        $mockV2Client = Mockery::mock(SesV2Client::class);
        $mockV2Client->shouldReceive('sendBulkEmail')
            ->once()
            ->andReturnUsing(function (array $payload) use (&$capturedPayload): Result {
                $capturedPayload = $payload;

                return new Result([]);
            });

        $mockClientManager = Mockery::mock(SesClientManager::class);
        $mockClientManager->shouldReceive('v2ClientFor')->andReturn($mockV2Client);

        $service = new SesBulkEmailService($mockClientManager, Mockery::mock(SesTenantService::class), app(SesCredentials::class));
        $service->sendBulkTemplatedEmail(
            'test-template',
            'sender@example.com',
            $fromName,
            [['email' => 'john@example.com', 'data' => [], 'tags' => []]],
            $user,
            [],
            null,
            $newsletter->message->fresh(),
        );

        return $capturedPayload;
    }
}
