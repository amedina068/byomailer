<?php

namespace App\Http\Resources;

use App\Enums\AbVariant;
use App\Models\Newsletter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Newsletter
 */
class NewsletterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $message = $this->message;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'is_mutable' => $this->isMutable(),
            'subject' => $message?->subject,
            'preview_text' => $message?->preview_text,
            'from_email' => $message?->from_email,
            'from_name' => $message?->from_name,
            'email_identity_id' => $message?->email_identity_id,
            'markdown_content' => $message?->markdown_content,
            'html_content' => $message?->html_content,
            'text_content' => $message?->text_content,
            'email_template_id' => $message?->email_template_id,
            'template_slot_content' => $message?->template_slot_content,
            'email_template' => $message?->template
                ? EmailTemplateResource::make($message->template)->resolve($request)
                : null,
            'scheduled_at' => $this->scheduled_at,
            'sent_at' => $this->sent_at,
            'email_identity' => new EmailIdentityResource($this->whenLoaded('emailIdentity')),
            'created_at' => $this->created_at,
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('id')->all()),
            'segment_id' => $this->segment_id,
            'filters' => $this->effectiveFilters(),
            'content_variables' => $this->content_variables ?? [],
            'ab_test_enabled' => $this->ab_test_enabled,
            'ab_test_winner_variant' => $this->ab_test_winner_variant?->value,
            'subject_b' => $this->whenLoaded('messageB', fn () => $this->messageB?->subject),
            'preview_text_b' => $this->whenLoaded('messageB', fn () => $this->messageB?->preview_text),
            'html_content_b' => $this->whenLoaded('messageB', fn () => $this->messageB?->html_content),
            'template_slot_content_b' => $this->whenLoaded('messageB', fn () => $this->messageB?->template_slot_content),
            'metrics' => $this->metrics(),
            'ab_metrics' => $this->ab_test_enabled ? [
                AbVariant::A->value => $this->variantMetrics(AbVariant::A),
                AbVariant::B->value => $this->variantMetrics(AbVariant::B),
            ] : null,
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function metrics(): array
    {
        $sent = (int) ($this->sent_count ?? 0);
        $delivered = (int) ($this->delivered_count ?? 0);
        $pending = (int) ($this->pending_count ?? 0);
        $opened = (int) ($this->opened_count ?? 0);
        $clicked = (int) ($this->clicked_count ?? 0);
        $bounced = (int) ($this->bounced_count ?? 0);
        $unsubscribed = (int) ($this->unsubscribed_count ?? 0);
        $complained = (int) ($this->complained_count ?? 0);
        $total = (int) ($this->total_contacts_reached ?? 0);

        if ($total === 0) {
            $total = (int) ($this->contact_mails_count ?? ($sent + $pending + $bounced + $unsubscribed + $complained));
        }

        return [
            'total' => $total,
            'sent' => $sent,
            'delivered' => $delivered,
            'pending' => $pending,
            'opened' => $opened,
            'clicked' => $clicked,
            'bounced' => $bounced,
            'unsubscribed' => $unsubscribed,
            'complained' => $complained,
            'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 1) : null,
            'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 1) : null,
            'click_rate' => $sent > 0 ? round(($clicked / $sent) * 100, 1) : null,
            'unsubscribe_rate' => $sent > 0 ? round(($unsubscribed / $sent) * 100, 1) : null,
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function variantMetrics(AbVariant $variant): array
    {
        $variantMessage = $variant === AbVariant::A ? $this->message : $this->messageB;

        if (! $variantMessage) {
            return [
                'total' => 0,
                'opened_count' => 0,
                'clicked_count' => 0,
                'open_rate' => null,
                'click_rate' => null,
            ];
        }

        $base = $this->contactMails()
            ->where('message_id', $variantMessage->id);

        $total = (clone $base)->count();
        $openedCount = (clone $base)->whereNotNull('opened_at')->count();
        $clickedCount = (clone $base)->whereNotNull('clicked_at')->count();

        return [
            'total' => $total,
            'opened_count' => $openedCount,
            'clicked_count' => $clickedCount,
            'open_rate' => $total > 0 ? round(($openedCount / $total) * 100, 1) : null,
            'click_rate' => $total > 0 ? round(($clickedCount / $total) * 100, 1) : null,
        ];
    }
}
