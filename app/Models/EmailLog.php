<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Database\Factories\EmailLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    /** @use HasFactory<EmailLogFactory> */
    use BelongsToClient, HasFactory;

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
            'request_payload' => 'array',
            'response_payload' => 'array',
            'sns_payload' => 'array',
            'logged_at' => 'datetime',
        ];
    }
}
