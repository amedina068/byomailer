<?php

namespace App\Models;

use App\Enums\DnsRecordPurpose;
use Database\Factories\EmailIdentityDnsRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property DnsRecordPurpose|null $purpose
 */
class EmailIdentityDnsRecord extends Model
{
    /** @use HasFactory<EmailIdentityDnsRecordFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'purpose' => DnsRecordPurpose::class,
        ];
    }

    /**
     * @return BelongsTo<EmailIdentity, $this>
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(EmailIdentity::class, 'email_identity_id');
    }
}
