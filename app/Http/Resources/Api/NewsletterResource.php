<?php

namespace App\Http\Resources\Api;

use App\Models\Newsletter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Newsletter
 */
class NewsletterResource extends JsonResource
{
    public bool $summary = false;

    public function summary(): self
    {
        $this->summary = true;

        return $this;
    }

    /**
     * @param  iterable<int, mixed>  $resource
     */
    public static function summaryCollection($resource): AnonymousResourceCollection
    {
        $collection = self::collection($resource);

        foreach ($collection->collection as $item) {
            if ($item instanceof self) {
                $item->summary = true;
            }
        }

        return $collection;
    }

    /**
     * @return array{
     *   id: int,
     *   uuid: string,
     *   title: string,
     *   status: string,
     *   subject: string|null,
     *   preview_text: string|null,
     *   email_identity_id: int|null,
     *   email_identity: array<string, mixed>|null,
     *   email_template_id: int|null,
     *   from_email: string|null,
     *   from_name: string|null,
     *   scheduled_at: string|null,
     *   sent_at: string|null,
     *   filters: array<int, array<string, mixed>>,
     *   content_variables: array<string, string|null>,
     *   ab_test_enabled: bool,
     *   ab_test_winner_variant: string|null,
     *   subject_b: string|null,
     *   preview_text_b: string|null,
     *   total_contacts_reached: int,
     *   project_ids?: array<int, int>,
     *   metrics: array{total: int, sent: int, pending: int, opened: int, bounced: int, unsubscribed: int, complained: int, open_rate: int|float, unsubscribe_rate: int|float},
     *   html_content?: string|null,
     *   text_content?: string|null,
     *   html_content_b?: string|null,
     *   text_content_b?: string|null,
     *   created_at: string|null,
     *   updated_at: string|null,
     * }
     */
    public function toArray(Request $request): array
    {
        $message = $this->message;
        $messageB = $this->messageB;
        $identity = $message?->emailIdentity ?? $this->emailIdentity;

        $payload = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'status' => $this->status->value,
            'subject' => $message?->subject,
            'preview_text' => $message?->preview_text,
            'email_identity_id' => $message?->email_identity_id,
            'email_identity' => $identity ? EmailIdentityResource::make($identity)->resolve($request) : null,
            'email_template_id' => $message?->email_template_id,
            'from_email' => $message?->from_email,
            'from_name' => $message?->from_name,
            'scheduled_at' => $this->scheduled_at,
            'sent_at' => $this->sent_at,
            'segment_id' => $this->segment_id,
            'filters' => $this->effectiveFilters(),
            'content_variables' => $this->content_variables ?? [],
            'ab_test_enabled' => (bool) $this->ab_test_enabled,
            'ab_test_winner_variant' => $this->ab_test_winner_variant === null ? null : $this->ab_test_winner_variant->value,
            'subject_b' => $messageB?->subject,
            'preview_text_b' => $messageB?->preview_text,
            'total_contacts_reached' => (int) ($this->total_contacts_reached ?? 0),
            'project_ids' => $this->whenLoaded('projects', fn () => $this->projects->pluck('id')->all()),
            'metrics' => $this->metrics(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if (! $this->summary) {
            $payload['html_content'] = $message?->html_content;
            $payload['text_content'] = $message?->text_content;
            $payload['html_content_b'] = $messageB?->html_content;
            $payload['text_content_b'] = $messageB?->text_content;
        }

        return $payload;
    }

    /**
     * @return array<string, int|float>
     */
    private function metrics(): array
    {
        $sent = (int) ($this->sent_count ?? 0);
        $pending = (int) ($this->pending_count ?? 0);
        $opened = (int) ($this->opened_count ?? 0);
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
            'pending' => $pending,
            'opened' => $opened,
            'bounced' => $bounced,
            'unsubscribed' => $unsubscribed,
            'complained' => $complained,
            'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 1) : 0,
            'unsubscribe_rate' => $sent > 0 ? round(($unsubscribed / $sent) * 100, 1) : 0,
        ];
    }
}
