<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUuid;
use Database\Factories\MailListFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class MailList extends Model
{
    /** @use HasFactory<MailListFactory> */
    use BelongsToClient, HasFactory, HasUuid;

    protected $table = 'lists';

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
            'opt_in_enabled' => 'boolean',
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
     * Keep the memberships that count for an audience: a list without opt-in,
     * or a contact that confirmed the opt-in email. Use it inside a query of
     * the lists of a contact, where the `contact_list` pivot is joined.
     *
     * @param  Builder<MailList>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->where('lists.opt_in_enabled', false)
            ->orWhereNotNull('contact_list.opted_in_at'));
    }

    /**
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_list', 'list_id', 'contact_id')
            ->withPivot('opted_in_at')
            ->withTimestamps();
    }

    /**
     * @return MorphOne<Message, $this>
     */
    public function message(): MorphOne
    {
        return $this->morphOne(Message::class, 'messageable');
    }

    /**
     * @return HasMany<Newsletter, $this>
     */
    public function newsletters(): HasMany
    {
        return $this->hasMany(Newsletter::class, 'list_id');
    }

    /**
     * @return HasMany<Topic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class, 'list_id');
    }

    /**
     * @return HasMany<ContactImport, $this>
     */
    public function contactImports(): HasMany
    {
        return $this->hasMany(ContactImport::class, 'list_id');
    }
}
