<?php

namespace App\Models;

use App\Enums\DkimStatus;
use App\Enums\DnsRecordPurpose;
use App\Enums\IdentityStatus;
use App\Enums\MailFromStatus;
use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUuid;
use Database\Factories\EmailIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property IdentityStatus $identity_status
 */
class EmailIdentity extends Model
{
    /** @use HasFactory<EmailIdentityFactory> */
    use BelongsToClient, HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected ?Collection $dnsRecordCache = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'last_status_check_at' => 'datetime',
            'identity_status' => IdentityStatus::class,
            'configuration_set_created_at' => 'datetime',
            'open_tracking_enabled' => 'boolean',
            'click_tracking_enabled' => 'boolean',
            'custom_tracking_domain_verified_at' => 'datetime',
            'custom_link_domain_verified_at' => 'datetime',
        ];
    }

    public function hasVerifiedLinkDomain(): bool
    {
        return $this->custom_link_domain !== null
            && $this->custom_link_domain_verified_at !== null;
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

    public function isVerified(): bool
    {
        return $this->identity_status === IdentityStatus::Verified;
    }

    /**
     * @return HasMany<EmailIdentityDnsRecord, $this>
     */
    public function dnsRecords(): HasMany
    {
        return $this->hasMany(EmailIdentityDnsRecord::class);
    }

    /**
     * @return array<int, array{type: string, name: string, value: string, priority?: int|null, purpose?: string, verified_at?: Carbon|null}>
     */
    public function getDnsRecords(): array
    {
        $records = $this->dnsRecordCollection();

        if ($records->isEmpty()) {
            return [];
        }

        return $records->map(function (EmailIdentityDnsRecord $record): array {
            $data = [
                'type' => $record->type,
                'name' => $record->name,
                'value' => $record->value,
            ];

            if (! is_null($record->priority)) {
                $data['priority'] = $record->priority;
            }

            if (! is_null($record->purpose)) {
                $data['purpose'] = $record->purpose->value;
            }

            if ($record->verified_at) {
                $data['verified_at'] = $record->verified_at;
            }

            return $data;
        })->toArray();
    }

    public function getDkimStatusAttribute(): DkimStatus
    {
        return $this->deriveDkimStatus();
    }

    public function getMailFromStatusAttribute(): MailFromStatus
    {
        return $this->deriveMailFromStatus();
    }

    private function deriveDkimStatus(): DkimStatus
    {
        $records = $this->dnsRecordCollection()
            ->where('purpose', DnsRecordPurpose::Dkim);

        if ($records->isEmpty()) {
            return DkimStatus::Pending;
        }

        return $records->every(fn (EmailIdentityDnsRecord $record) => ! is_null($record->verified_at))
            ? DkimStatus::Success
            : DkimStatus::Pending;
    }

    private function deriveMailFromStatus(): MailFromStatus
    {
        if (! $this->mail_from_subdomain) {
            return MailFromStatus::Pending;
        }

        $records = $this->dnsRecordCollection()
            ->filter(fn (EmailIdentityDnsRecord $record) => in_array($record->type, ['MX', 'TXT'], true)
                && $record->name === $this->mail_from_subdomain
                && $record->value !== 'v=DMARC1; p=none;');

        if ($records->isEmpty()) {
            return MailFromStatus::Pending;
        }

        return $records->every(fn (EmailIdentityDnsRecord $record) => ! is_null($record->verified_at))
            ? MailFromStatus::Success
            : MailFromStatus::Pending;
    }

    private function dnsRecordCollection(): Collection
    {
        if ($this->dnsRecordCache !== null) {
            return $this->dnsRecordCache;
        }

        $this->dnsRecordCache = $this->relationLoaded('dnsRecords')
            ? $this->dnsRecords
            : $this->dnsRecords()->get();

        return $this->dnsRecordCache;
    }
}
