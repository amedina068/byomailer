<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Database\Factories\UserStorageSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserStorageSetting extends Model
{
    /** @use HasFactory<UserStorageSettingFactory> */
    use BelongsToClient, HasFactory;

    public const PROVIDER_S3 = 's3';

    public const PROVIDER_R2 = 'r2';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The keys are secrets. They never go into an array or a JSON response.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_key_id',
        'secret_access_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_key_id' => 'encrypted',
            'secret_access_key' => 'encrypted',
            'is_verified' => 'boolean',
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
     * Return the path prefix with the {user_id} placeholder resolved.
     */
    public function resolvedPathPrefix(): string
    {
        return str_replace('{user_id}', (string) $this->user_id, $this->path_prefix);
    }
}
