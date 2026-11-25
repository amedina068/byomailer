<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property UserRole $role
 */
class User extends Authenticatable implements HasMedia
{
    /** The login form and a closed session show this text to a deactivated user. */
    public const DEACTIVATED_MESSAGE = 'This account is deactivated. Ask the owner of this BYOMailer instance to reactivate it.';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, InteractsWithMedia, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'invite_expires_at' => 'datetime',
            'archived_at' => 'datetime',
            'role' => UserRole::class,
            'is_owner' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * The owner runs this instance of BYOMailer and can open the admin area.
     * The owner is not the same as a team admin (`UserRole::Admin`) of a client.
     */
    public function isOwner(): bool
    {
        return (bool) $this->is_owner;
    }

    /**
     * A deactivated user keeps all data and cannot authenticate. Only the
     * owner deactivates and reactivates users, in the admin area.
     */
    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function deactivate(): void
    {
        $this->forceFill(['deactivated_at' => now()])->save();
    }

    public function reactivate(): void
    {
        $this->forceFill(['deactivated_at' => null])->save();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isInvited(): bool
    {
        return $this->role === UserRole::Invited;
    }

    /**
     * A session has read access. An API token needs the `read` ability.
     * Tokens made before the abilities existed have `*`, so they keep it.
     */
    public function hasReadAccess(): bool
    {
        $token = $this->currentAccessToken();

        return $token === null || $token->can('read');
    }

    /**
     * A Viewer never has write access. An API token also needs the `write`
     * ability.
     */
    public function hasWriteAccess(): bool
    {
        if ($this->role === UserRole::Viewer) {
            return false;
        }

        $token = $this->currentAccessToken();

        return $token === null || $token->can('write');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasMany<MailList, $this>
     */
    public function mailingLists(): HasMany
    {
        return $this->hasMany(MailList::class, 'user_id');
    }

    /**
     * @return HasMany<ContactAttribute, $this>
     */
    public function contactAttributes(): HasMany
    {
        return $this->hasMany(ContactAttribute::class);
    }

    /**
     * @return HasMany<Newsletter, $this>
     */
    public function newsletters(): HasMany
    {
        return $this->hasMany(Newsletter::class);
    }

    /**
     * @return HasMany<EmailIdentity, $this>
     */
    public function emailIdentities(): HasMany
    {
        return $this->hasMany(EmailIdentity::class);
    }

    /**
     * The credentials belong to the client, so each team member reads the
     * same AWS keys.
     *
     * @return HasMany<EmailCredential, $this>
     */
    public function emailCredentials(): HasMany
    {
        return $this->hasMany(EmailCredential::class, 'client_id', 'client_id');
    }

    /**
     * @return HasOne<EmailCredential, $this>
     */
    public function sesCredential(): HasOne
    {
        return $this->hasOne(EmailCredential::class, 'client_id', 'client_id')->where('type', 'ses');
    }

    /**
     * @return HasMany<Automation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    /**
     * @return HasMany<VisualAutomation, $this>
     */
    public function visualAutomations(): HasMany
    {
        return $this->hasMany(VisualAutomation::class);
    }

    /**
     * @return HasMany<Sequence, $this>
     */
    public function sequences(): HasMany
    {
        return $this->hasMany(Sequence::class);
    }

    /**
     * @return HasMany<BlacklistedEmail, $this>
     */
    public function blacklistedEmails(): HasMany
    {
        return $this->hasMany(BlacklistedEmail::class);
    }

    /**
     * @return HasMany<ContactImport, $this>
     */
    public function contactImports(): HasMany
    {
        return $this->hasMany(ContactImport::class);
    }

    /**
     * @return HasOne<UserStorageSetting, $this>
     */
    public function storageSetting(): HasOne
    {
        return $this->hasOne(UserStorageSetting::class);
    }

    /**
     * @return HasMany<Block, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    /**
     * @return HasMany<EmailTemplate, $this>
     */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }
}
