<?php

namespace App\Models;

use App\Enums\ClientRole;
use App\Models\Concerns\HasUuid;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property ClientRole $role
 */
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasApiTokens, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * The external ID goes into the trust policy of the IAM role of the
     * account. It never goes into an array or a JSON response.
     *
     * @var list<string>
     */
    protected $hidden = [
        'aws_external_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ClientRole::class,
            'sunset_inactive_days' => 'integer',
        ];
    }

    /**
     * Get the external ID for the IAM role of this account.
     *
     * BYOMailer makes the ID one time from random bytes. The account cannot
     * choose it. The update only writes an empty column, so two requests at
     * the same time keep the ID that was saved first.
     */
    public function awsExternalId(): string
    {
        if ($this->aws_external_id === null) {
            static::query()
                ->whereKey($this->getKey())
                ->whereNull('aws_external_id')
                ->update(['aws_external_id' => bin2hex(random_bytes(32))]);

            $this->aws_external_id = static::query()->whereKey($this->getKey())->value('aws_external_id');
            $this->syncOriginalAttribute('aws_external_id');
        }

        return $this->aws_external_id;
    }

    public function isAdmin(): bool
    {
        return $this->role === ClientRole::Admin;
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Block, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
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
        return $this->hasMany(MailList::class, 'client_id');
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Newsletter, $this>
     */
    public function newsletters(): HasMany
    {
        return $this->hasMany(Newsletter::class);
    }

    /**
     * @return HasMany<Sequence, $this>
     */
    public function sequences(): HasMany
    {
        return $this->hasMany(Sequence::class);
    }

    /**
     * @return HasMany<Segment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    /**
     * @return HasMany<Automation, $this>
     */
    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    /**
     * @return HasMany<BlacklistedEmail, $this>
     */
    public function blacklistedEmails(): HasMany
    {
        return $this->hasMany(BlacklistedEmail::class);
    }

    /**
     * @return HasMany<EmailCredential, $this>
     */
    public function emailCredentials(): HasMany
    {
        return $this->hasMany(EmailCredential::class);
    }

    /**
     * @return HasOne<EmailCredential, $this>
     */
    public function sesCredential(): HasOne
    {
        return $this->hasOne(EmailCredential::class)->where('type', 'ses');
    }

    /**
     * @return HasMany<EmailIdentity, $this>
     */
    public function emailIdentities(): HasMany
    {
        return $this->hasMany(EmailIdentity::class);
    }

    /**
     * @return HasMany<ContactAttribute, $this>
     */
    public function contactAttributes(): HasMany
    {
        return $this->hasMany(ContactAttribute::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<ContactMail, $this>
     */
    public function contactMails(): HasMany
    {
        return $this->hasMany(ContactMail::class);
    }

    /**
     * @return HasMany<EmailLog, $this>
     */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    /**
     * @return HasMany<ContactImport, $this>
     */
    public function contactImports(): HasMany
    {
        return $this->hasMany(ContactImport::class);
    }

    /**
     * @return HasMany<UserStorageSetting, $this>
     */
    public function storageSettings(): HasMany
    {
        return $this->hasMany(UserStorageSetting::class);
    }

    /**
     * @return HasMany<EmailTemplate, $this>
     */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class);
    }
}
