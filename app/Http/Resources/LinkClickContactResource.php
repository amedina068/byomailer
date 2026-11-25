<?php

namespace App\Http\Resources;

use App\Models\ContactMail;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One contact who clicked a link. The resource wraps a contact mail that
 * `LinkClickReport::clickers()` loaded with the click count and the time of
 * the first click on that link.
 *
 * @mixin ContactMail
 */
class LinkClickContactResource extends JsonResource
{
    /**
     * @return array{contact_mail_id: int, contact_id: int, email: ?string, name: ?string, clicks: int, first_clicked_at: ?string}
     */
    public function toArray(Request $request): array
    {
        $firstClickedAt = $this->resource->getAttribute('link_first_clicked_at');

        return [
            'contact_mail_id' => $this->id,
            'contact_id' => $this->contact_id,
            'email' => $this->contact?->email,
            'name' => $this->contact->name ?? null,
            'clicks' => (int) $this->resource->getAttribute('link_clicks_count'),
            'first_clicked_at' => $firstClickedAt ? CarbonImmutable::parse($firstClickedAt)->toIso8601String() : null,
        ];
    }
}
