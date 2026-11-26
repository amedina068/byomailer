<?php

namespace App\Models;

use App\Enums\AbVariant;
use App\Models\Concerns\BelongsToClient;
use App\Scopes\ClientScope;
use App\Support\ContentSlot;
use App\Support\HtmlToText;
use App\Support\Liquid;
use App\Support\ReplacesBlockVariables;
use App\Support\ReplacesTemplateVariables;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $client_id
 * @property string $subject
 * @property string|null $preview_text
 * @property string $markdown_content
 * @property string $html_content
 * @property string|null $text_content
 * @property string|null $from_email
 * @property string|null $from_name
 * @property int|null $email_identity_id
 * @property int|null $email_template_id
 * @property AbVariant|null $variant
 * @property string $messageable_type
 * @property int $messageable_id
 * @property EmailIdentity|null $emailIdentity
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use BelongsToClient, HasFactory, ReplacesBlockVariables, ReplacesTemplateVariables;

    /**
     * @var list<string>
     */
    public const RESERVED_TEMPLATE_FIELDS = ['unsubscribe_url', 'preferences_url', 'postal_address'];

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variant' => AbVariant::class,
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<EmailIdentity, $this>
     */
    public function emailIdentity(): BelongsTo
    {
        return $this->belongsTo(EmailIdentity::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function messageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function body(): string
    {
        if ($this->html_content) {
            return (string) $this->html_content;
        }

        if ($this->text_content) {
            return (string) $this->text_content;
        }

        return '';
    }

    /**
     * A send asks for the fields once for each contact. The message keeps the
     * result, so the template is parsed one time for each instance.
     *
     * @return array<int, string>
     */
    public function templateFields(): array
    {
        return once(function (): array {
            if ($this->usesLiquid()) {
                return collect($this->templateParts())
                    ->flatMap(fn (string $part): array => Liquid::variables($part))
                    ->unique()
                    ->values()
                    ->all();
            }

            $haystack = $this->withBlocksExpanded(
                $this->body()
                .' '.($this->currentTemplate() ? $this->composedHtml() : '')
                .' '.((string) $this->subject)
                .' '.((string) $this->preview_text)
            );

            if (trim($haystack) === '') {
                return [];
            }

            preg_match_all('/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/', $haystack, $matches);

            return collect($matches[1] ?? [])
                ->map(fn (string $match) => trim($match))
                ->filter()
                ->reject(fn (string $field): bool => str_starts_with($field, 'block.'))
                ->unique()
                ->values()
                ->all();
        });
    }

    /**
     * True when the message uses Liquid code and each part of it is valid
     * Liquid. Plain `{{ name }}` content and content that is not valid Liquid
     * keep the replacement from before Liquid, so they send the same as before.
     */
    public function usesLiquid(): bool
    {
        return once(function (): bool {
            $parts = $this->templateParts();

            if (Liquid::isPlain(implode("\n", $parts))) {
                return false;
            }

            return collect($parts)->every(fn (string $part): bool => Liquid::error($part) === null);
        });
    }

    /**
     * Render one part of the message with the template data of a contact.
     * Text parts (the subject, the preview text and the text body) are not
     * escaped. The HTML part escapes each value.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $content, array $data, bool $html = true): string
    {
        if ($this->usesLiquid()) {
            return Liquid::render($content, $data, $html);
        }

        return $html
            ? $this->replaceTemplateVariablesInHtml($content, $data)
            : $this->replaceTemplateVariables($content, $data);
    }

    /**
     * The parts of the message that a send renders, with the blocks expanded.
     *
     * @return list<string>
     */
    private function templateParts(): array
    {
        return array_values(array_filter(array_map(
            fn (?string $part): string => $this->withBlocksExpanded((string) $part),
            [
                $this->currentTemplate() ? $this->composedHtml() : $this->html_content,
                $this->text_content,
                $this->subject,
                $this->preview_text,
            ],
        ), fn (string $part): bool => trim($part) !== ''));
    }

    /**
     * The send expands the blocks before it replaces the variables, so the
     * variables of a block must be in the template data too.
     */
    private function withBlocksExpanded(string $content): string
    {
        if (! str_contains($content, 'block.') || ! $this->client) {
            return $content;
        }

        return $this->replaceBlockVariables($content, $this->client);
    }

    /**
     * Liquid content keeps a list attribute as a list, so a `{% for %}` loop
     * can read it. Plain content gets the text of the old replacement.
     *
     * @return array<string, string|array<mixed>|null>
     */
    public function templateData(Contact $contact, ContactMail $contactMail): array
    {
        return collect($this->templateFields())
            ->mapWithKeys(function (string $field) use ($contact, $contactMail): array {
                if ($field === 'unsubscribe_url') {
                    return [$field => $contactMail->unsubscribe_link ?? ''];
                }

                if ($field === 'preferences_url') {
                    return [$field => $contactMail->preferences_link ?? ''];
                }

                if ($field === 'postal_address') {
                    return [$field => $this->postalAddress()];
                }

                return [$field => $this->usesLiquid() ? $contact->getTemplateValue($field) : $contact->getTemplateAttribute($field)];
            })
            ->all();
    }

    /**
     * The send asks for the postal address once for each contact. The message
     * keeps the result, so the client is read one time for each instance.
     */
    private function postalAddress(): string
    {
        return once(fn (): string => (string) Client::query()->whereKey($this->client_id)->value('postal_address'));
    }

    /**
     * Resolve template data with fallback tiers: contact value, then
     * per-newsletter/sequence content variable defaults, then the client's
     * global attribute defaults.
     *
     * @param  array<string, string|null>  $contentVariableDefaults
     * @param  array<string, string>  $globalAttributeDefaults
     * @return array<string, string|array<mixed>|null>
     */
    public function resolveTemplateData(Contact $contact, ContactMail $contactMail, array $contentVariableDefaults = [], array $globalAttributeDefaults = []): array
    {
        $templateData = $this->templateData($contact, $contactMail);

        foreach ([$contentVariableDefaults, $globalAttributeDefaults] as $defaults) {
            foreach ($defaults as $key => $defaultValue) {
                // An empty default leaves a Liquid variable null, so `{% if %}` reads it as false.
                if ($this->usesLiquid() && ($defaultValue === null || $defaultValue === '')) {
                    continue;
                }

                if (! isset($templateData[$key]) || $templateData[$key] === '') {
                    $templateData[$key] = (string) ($defaultValue ?? '');
                }
            }
        }

        return $templateData;
    }

    /**
     * Global attribute defaults for this message's detected variables, keyed
     * by attribute name. Bounded to detected fields so callers never persist
     * unrelated attributes into contact_mails.template_data. Runs in
     * background contexts, so the caller passes the tenant explicitly.
     *
     * @return array<string, string>
     */
    public function globalAttributeDefaults(?int $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        $fields = collect($this->templateFields())
            ->reject(fn (string $field): bool => in_array($field, self::RESERVED_TEMPLATE_FIELDS, true))
            ->values()
            ->all();

        return ContactAttribute::defaultsForNames($fields, $clientId);
    }

    /**
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    /**
     * Resolve the template bound to this message. Safe in background/queue
     * contexts where ClientScope is a no-op — looked up by primary key.
     */
    public function currentTemplate(): ?EmailTemplate
    {
        if (! $this->email_template_id) {
            return null;
        }

        if (! $this->relationLoaded('template')) {
            $template = EmailTemplate::query()
                ->withoutGlobalScope(ClientScope::class)
                ->where(function (Builder $query): void {
                    $query->where('client_id', $this->client_id)->orWhereNull('client_id');
                })
                ->find($this->email_template_id);

            $this->setRelation('template', $template);
        }

        $template = $this->template;

        return $template && ($template->client_id === null || $template->client_id === $this->client_id)
            ? $template
            : null;
    }

    /**
     * Body HTML composed with the template's CURRENT html_content, re-hydrating
     * the live template around the stored slot content so template edits reach
     * not-yet-sent messages at send time. Falls back to the stored html_content
     * when the message is not bound to a template.
     */
    public function composedHtml(): string
    {
        $template = $this->currentTemplate();

        if (! $template || $this->template_slot_content === null) {
            return (string) $this->html_content;
        }

        return ContentSlot::hydrate(
            $template->html_content,
            (string) ($this->template_slot_content ?? ''),
        );
    }

    /**
     * Plain-text body kept in sync with composedHtml(). Recomputed from the
     * current template when one is bound; otherwise the stored text_content.
     */
    public function composedText(): string
    {
        if (! $this->currentTemplate()) {
            return (string) $this->text_content;
        }

        return HtmlToText::convert(ContentSlot::unwrap($this->composedHtml()));
    }
}
