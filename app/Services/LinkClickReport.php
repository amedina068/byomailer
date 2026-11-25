<?php

namespace App\Services;

use App\Models\ContactMail;
use App\Models\ContactMailClick;
use App\Models\Newsletter;
use App\Models\SequenceStep;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The clicks of each link of one newsletter or one sequence step.
 *
 * A named link groups by its name. Any other link groups by its URL without
 * the `utm_*` parameters and the fragment. So the UTM tags of each send do
 * not split one destination into many rows. Each group has a key:
 * `name:<link name>` or `url:<normalized url>`.
 *
 * The clicks table has no `client_id`. Each query reads clicks through the
 * contact mails of the mailable, and `ClientScope` bounds those to the client.
 */
class LinkClickReport
{
    private const NAME_PREFIX = 'name:';

    private const URL_PREFIX = 'url:';

    /**
     * @return array{delivered: int, links: list<array{key: string, name: ?string, url: ?string, unique_contacts: int, total_clicks: int, click_rate: ?float}>}
     */
    public function summary(Newsletter|SequenceStep $mailable): array
    {
        $groups = [];

        $clicks = ContactMailClick::query()
            ->whereIn('contact_mail_id', $mailable->contactMails()->select('id'))
            ->select(['link_name', 'link', 'contact_id'])
            ->toBase()
            ->cursor();

        foreach ($clicks as $click) {
            $url = self::normalizeUrl($click->link);
            $key = $click->link_name !== null ? self::NAME_PREFIX.$click->link_name : self::URL_PREFIX.$url;

            $groups[$key] ??= ['name' => $click->link_name, 'urls' => [], 'contacts' => [], 'total_clicks' => 0];
            $groups[$key]['contacts'][(int) $click->contact_id] = true;
            $groups[$key]['total_clicks']++;

            if ($url !== null) {
                $groups[$key]['urls'][$url] = ($groups[$key]['urls'][$url] ?? 0) + 1;
            }
        }

        $delivered = $mailable->contactMails()->whereNotNull('delivered_at')->count();

        $links = collect($groups)
            ->map(function (array $group, string $key) use ($delivered): array {
                arsort($group['urls']);
                $uniqueContacts = count($group['contacts']);
                $url = array_key_first($group['urls']);

                return [
                    'key' => (string) $key,
                    'name' => $group['name'] === null ? null : (string) $group['name'],
                    'url' => $url === null ? null : (string) $url,
                    'unique_contacts' => $uniqueContacts,
                    'total_clicks' => (int) $group['total_clicks'],
                    'click_rate' => $delivered > 0 ? round($uniqueContacts / $delivered * 100, 2) : null,
                ];
            })
            ->sortBy([['unique_contacts', 'desc'], ['total_clicks', 'desc'], ['key', 'asc']])
            ->values()
            ->all();

        return ['delivered' => $delivered, 'links' => $links];
    }

    /**
     * Keep the contact mails with a click on the link of the key. Load the
     * click count and the time of the first click on that link.
     *
     * @param  Builder<ContactMail>  $query
     * @return Builder<ContactMail>
     */
    public function filterContactMails(Builder $query, Newsletter|SequenceStep $mailable, string $key): Builder
    {
        $constraint = $this->matchingClicks($mailable, $key);

        return $query
            ->whereHas('clicks', $constraint)
            ->withCount(['clicks as link_clicks_count' => $constraint])
            ->withMin(['clicks as link_first_clicked_at' => $constraint], 'clicked_at');
    }

    /**
     * The contact mails of the mailable with a click on the link of the key,
     * with the contact loaded, in id order.
     *
     * @return Builder<ContactMail>
     */
    public function clickers(Newsletter|SequenceStep $mailable, string $key): Builder
    {
        return $this->filterContactMails($mailable->contactMails()->getQuery(), $mailable, $key)
            ->with('contact:id,email,name')
            ->orderBy('id');
    }

    /**
     * A `whereHas('clicks', ...)` constraint that keeps the clicks on the link
     * of the key.
     *
     * @return Closure(Builder<ContactMailClick>): mixed
     */
    public function matchingClicks(Newsletter|SequenceStep $mailable, string $key): Closure
    {
        if (str_starts_with($key, self::NAME_PREFIX)) {
            $name = substr($key, strlen(self::NAME_PREFIX));

            return fn (Builder $clicks) => $clicks->where('link_name', $name);
        }

        $url = substr($key, strlen(self::URL_PREFIX));

        $links = ContactMailClick::query()
            ->whereIn('contact_mail_id', $mailable->contactMails()->select('id'))
            ->whereNull('link_name')
            ->pluck('link')
            ->unique()
            ->filter(fn (?string $link): bool => self::normalizeUrl($link) === $url)
            ->values()
            ->all();

        return fn (Builder $clicks) => $clicks->whereNull('link_name')->whereIn('link', $links);
    }

    public static function isValidKey(mixed $key): bool
    {
        return is_string($key)
            && strlen($key) <= 2048
            && ((str_starts_with($key, self::NAME_PREFIX) && strlen($key) > strlen(self::NAME_PREFIX))
                || (str_starts_with($key, self::URL_PREFIX) && strlen($key) > strlen(self::URL_PREFIX)));
    }

    /**
     * Remove the `utm_*` parameters and the fragment, and lowercase the scheme
     * and the host. The other query parameters keep their order.
     */
    public static function normalizeUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return trim($url);
        }

        $query = collect(explode('&', $parts['query'] ?? ''))
            ->filter(fn (string $pair): bool => $pair !== '' && ! str_starts_with(strtolower($pair), 'utm_'))
            ->implode('&');

        return strtolower($parts['scheme'] ?? 'https').'://'
            .strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .($query !== '' ? '?'.$query : '');
    }
}
