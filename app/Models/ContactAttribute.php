<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUuid;
use Database\Factories\ContactAttributeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactAttribute extends Model
{
    /** @use HasFactory<ContactAttributeFactory> */
    use BelongsToClient, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Default values for the given attribute names, keyed by name.
     *
     * With a $clientId the query bypasses the global scope and bounds tenancy
     * explicitly. Without one it uses ClientScope, which refuses a query with
     * no tenant.
     *
     * The trailing only() is NOT redundant with the whereIn: MySQL's ci
     * collation lets whereIn match a row whose stored casing differs from
     * the requested name, which would inject a wrong-cased key into the map.
     * Attribute matching is exact-case by design across all tiers.
     *
     * @param  array<int, string>  $names
     * @return array<string, string>
     */
    public static function defaultsForNames(array $names, ?int $clientId = null): array
    {
        if ($names === []) {
            return [];
        }

        $query = $clientId === null
            ? static::query()
            : static::withoutGlobalScopes()->where('client_id', $clientId);

        return $query
            ->whereIn('name', $names)
            ->whereNotNull('default_value')
            ->pluck('default_value', 'name')
            ->only($names)
            ->all();
    }

    /**
     * Merge stored per-newsletter/sequence defaults with global attribute
     * defaults for display prefill. A stored '' counts as absent so the
     * global default surfaces even after the variables step has been saved.
     *
     * @param  array<int, string>  $names
     * @param  array<string, string|null>  $existingDefaults
     * @param  array<string, string>  $globalDefaults
     * @return array<string, string>
     */
    public static function mergePrefillDefaults(array $names, array $existingDefaults, array $globalDefaults): array
    {
        return collect($names)
            ->mapWithKeys(fn (string $name): array => [
                $name => ($existingDefaults[$name] ?? '') !== ''
                    ? $existingDefaults[$name]
                    : ($globalDefaults[$name] ?? ''),
            ])
            ->all();
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
}
