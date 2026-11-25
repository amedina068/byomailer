<?php

namespace App\Services;

use App\Concerns\AppliesUtmParameters;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\User;
use App\Support\ContentSlot;
use App\Support\HtmlToText;
use App\Support\Preheader;
use App\Support\QueryStringJoiner;
use App\Support\ReplacesBlockVariables;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class SesBulkEmailService
{
    use AppliesUtmParameters;
    use ReplacesBlockVariables;

    public function __construct(
        private SesClientManager $clients,
        private SesTenantService $tenantService,
    ) {}

    public function createNewsletterTemplate(Newsletter $newsletter, User $user, ?Message $messageOverride = null): string
    {
        $message = $messageOverride ?? $newsletter->message;

        if (! $message) {
            throw new RuntimeException('Newsletter does not have a message to render.');
        }

        $templateName = 'newsletter-'.$newsletter->id.'-'.Str::uuid();
        $client = $user->client;

        // Blocks must be expanded before the template reaches SES. SES resolves
        // `{{ }}` as handlebars against the per-recipient data, and block names
        // are never in that data, so an unexpanded `{{ block.footer }}` renders
        // as nothing — silently dropping the footer from every newsletter.
        $htmlContent = $this->replaceBlockVariables(ContentSlot::unwrap($message->composedHtml()), $client);
        $subject = $this->replaceBlockVariablesAsText((string) $message->subject, $client);
        $previewText = $this->replaceBlockVariablesAsText((string) ($message->preview_text ?? ''), $client);

        $htmlContent = self::addUtmParametersToHtml($htmlContent, self::defaultUtmParameters($newsletter));

        // A link that starts with a variable gets its UTM parameters from the
        // per-recipient data, because SES fills the variable at send time.
        $htmlContent = self::pointVariableLinksAtUtmValues($htmlContent);

        // A stored plain body gets the text rendering of the block, never its
        // HTML. Otherwise `text/plain` ships with tags in it.
        $textContent = $message->currentTemplate() === null && $message->text_content
            ? $this->replaceBlockVariablesAsText($message->text_content, $client)
            : HtmlToText::convert($htmlContent);

        $this->clients->v2ClientFor($user)->createEmailTemplate([
            'TemplateName' => $templateName,
            'TemplateContent' => [
                'Subject' => $subject,
                'Html' => $this->buildHtmlTemplate(QueryStringJoiner::rewriteForTemplate($htmlContent), $subject, $previewText),
                'Text' => $this->buildTextTemplate($textContent),
            ],
        ]);

        $credential = $user->sesCredential;

        if ($credential?->tenant_name && $credential->aws_account_id) {
            $templateArn = SesTenantService::buildTemplateArn($credential->region, $credential->aws_account_id, $templateName);

            $this->tenantService->associateResource(
                $credential->tenant_name,
                $templateArn,
                $credential->access_key,
                $credential->secret_key,
                $credential->region,
            );
        }

        return $templateName;
    }

    /**
     * @param  array<int, array<string, mixed>>  $destinations
     * @param  array<string, string>  $defaultTemplateData
     * @return array<int, array{Status: string, Error?: string, MessageId?: string}>
     *
     * @throws JsonException
     */
    public function sendBulkTemplatedEmail(string $templateName, string $fromEmail, ?string $fromName, array $destinations, User $user, array $defaultTemplateData, ?string $tenantName, Message $message): array
    {
        if (empty($destinations)) {
            return [];
        }

        $configurationSetName = $message->emailIdentity->configuration_set_name;

        $source = self::formatMailbox($fromEmail, $fromName);

        $utmParams = self::utmParametersFor($message);

        $bulkEmailEntries = array_map(function (array $destination) use ($utmParams) {
            $data = $destination['data'] ?? [];
            $tags = array_values($destination['tags'] ?? []);
            $unsubscribeUrl = $destination['unsubscribe_url'] ?? '';
            $oneClickUrl = $destination['one_click_unsubscribe_url'] ?? '';

            $data['unsubscribe_url'] = $unsubscribeUrl;
            $data = QueryStringJoiner::withSeparators(self::withUtmValues($data, $utmParams));

            $entry = [
                'Destination' => [
                    'ToAddresses' => [$destination['email']],
                ],
                'ReplacementEmailContent' => [
                    'ReplacementTemplate' => [
                        'ReplacementTemplateData' => json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'ReplacementTags' => $tags,
            ];

            if ($oneClickUrl) {
                $entry['ReplacementHeaders'] = [
                    [
                        'Name' => 'List-Unsubscribe',
                        'Value' => "<{$oneClickUrl}>",
                    ],
                    [
                        'Name' => 'List-Unsubscribe-Post',
                        'Value' => 'List-Unsubscribe=One-Click',
                    ],
                ];
            }

            return $entry;
        }, $destinations);

        $payload = [
            'FromEmailAddress' => $source,
            'DefaultContent' => [
                'Template' => [
                    'TemplateName' => $templateName,
                    'TemplateData' => json_encode((object) QueryStringJoiner::withSeparators(self::withUtmValues($defaultTemplateData, $utmParams)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
            'BulkEmailEntries' => $bulkEmailEntries,
        ];

        if ($tenantName !== null) {
            $payload['TenantName'] = $tenantName;
        }

        $payload['ConfigurationSetName'] = $configurationSetName;

        $replyToEmail = $message->emailIdentity->reply_to_email;

        if (filled($replyToEmail)) {
            $payload['ReplyToAddresses'] = [self::formatMailbox($replyToEmail, $message->emailIdentity->reply_to_name)];
        }

        $response = $this->clients->v2ClientFor($user)->sendBulkEmail($payload);

        return $response['BulkEmailEntryResults'] ?? [];
    }

    public function deleteTemplate(string $templateName, User $user): void
    {
        $credential = $user->sesCredential;

        if ($credential?->tenant_name && $credential->aws_account_id) {
            $templateArn = SesTenantService::buildTemplateArn($credential->region, $credential->aws_account_id, $templateName);

            $result = $this->tenantService->dissociateResource(
                $credential->tenant_name,
                $templateArn,
                $credential->access_key,
                $credential->secret_key,
                $credential->region,
            );

            if (! $result['success']) {
                Log::warning('Failed to dissociate template from tenant', [
                    'template' => $templateName,
                    'tenant' => $credential->tenant_name,
                    'arn' => $templateArn,
                    'error' => $result['error'] ?? 'Unknown',
                ]);
            }
        }

        $this->clients->v2ClientFor($user)->deleteEmailTemplate([
            'TemplateName' => $templateName,
        ]);
    }

    /**
     * Format an RFC 5322 mailbox for the SES v2 API. SES needs a non-ASCII
     * display name as a MIME encoded-word. An ASCII name with characters
     * outside atext and spaces is quoted and escaped, so a comma or a quote
     * cannot split the address.
     */
    private static function formatMailbox(string $email, ?string $name): string
    {
        if (blank($name)) {
            return $email;
        }

        if (! mb_check_encoding($name, 'ASCII')) {
            return sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($name), $email);
        }

        if (preg_match('/^[A-Za-z0-9!#$%&\'*+\-\/=?^_`{|}~ ]+$/', $name) !== 1) {
            $name = '"'.addcslashes($name, '"\\').'"';
        }

        return sprintf('%s <%s>', $name, $email);
    }

    /**
     * The UTM parameters of the newsletter that owns the message. They match the
     * parameters that createNewsletterTemplate() adds to the fixed links.
     *
     * @return array<string, string>
     */
    private static function utmParametersFor(Message $message): array
    {
        $newsletter = $message->messageable;

        return $newsletter instanceof Newsletter ? self::defaultUtmParameters($newsletter) : [];
    }

    /**
     * @return array<string, string>
     */
    private static function defaultUtmParameters(Newsletter $newsletter): array
    {
        $subject = Str::slug(trim($newsletter->message?->subject ?? '')) ?: 'newsletter';

        return [
            'utm_medium' => 'mail',
            'utm_source' => $subject,
            'utm_campaign' => 'newsletter',
        ];
    }

    private function buildHtmlTemplate(string $content, string $subject, string $previewText = ''): string
    {
        $body = trim($content);
        $escapedSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $preheader = Preheader::html($previewText);

        if ($preheader !== '') {
            $preheader .= "\n";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{$escapedSubject}</title>
</head>
<body>
{$preheader}{$body}
</body>
</html>
HTML;
    }

    /**
     * SES HTML-escapes `{{ var }}` in the text part too, so a URL with `=` or `&` shows
     * as `&#x3D;` in `text/plain`. Triple braces output the value unescaped.
     */
    private function buildTextTemplate(string $content): string
    {
        $content = QueryStringJoiner::rewriteForTemplate(trim($content));

        return preg_replace('/(?<!\{)\{\{\s*(?!else\s*\}\})([\w.]+)\s*\}\}(?!\})/', '{{{ $1 }}}', $content) ?? $content;
    }
}
