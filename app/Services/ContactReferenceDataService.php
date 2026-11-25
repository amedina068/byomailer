<?php

namespace App\Services;

use App\Enums\SequenceStatus;
use App\Models\MailList;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class ContactReferenceDataService
{
    private const TTL = 60;

    /**
     * Mailing lists shown in the contact filter/assignment pickers.
     *
     * @return Collection<int, MailList>
     */
    public function lists(User $user): Collection
    {
        return $this->rememberSliding(
            "contact-reference:lists:{$user->client_id}",
            fn (): Collection => MailList::query()->where('client_id', $user->client_id)->orderBy('name')->get()
        );
    }

    /**
     * @return Collection<int, Tag>
     */
    public function tags(User $user): Collection
    {
        return $this->rememberSliding(
            "contact-reference:tags:{$user->client_id}",
            fn (): Collection => Tag::query()->where('client_id', $user->client_id)->orderBy('name')->get()
        );
    }

    /**
     * Live sequences a contact can be enrolled into.
     *
     * @return Collection<int, Sequence>
     */
    public function liveSequences(User $user): Collection
    {
        return $this->rememberSliding(
            "contact-reference:live-sequences:{$user->client_id}",
            fn (): Collection => Sequence::query()
                ->where('client_id', $user->client_id)
                ->where('status', SequenceStatus::Live)
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Cache the value, then extend its TTL on every read so tenants who browse
     * actively keep a warm cache instead of paying a rebuild every minute.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $callback
     * @return TValue
     */
    private function rememberSliding(string $key, Closure $callback): mixed
    {
        $value = Cache::remember($key, self::TTL, $callback);
        Cache::touch($key, self::TTL);

        return $value;
    }
}
