<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Send-time suppression looks up `blacklisted_emails` by email, correlated
 * against `contacts.email`. The only existing index is `(client_id, email)`,
 * which that lookup cannot use because it does not lead with `client_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blacklisted_emails', function (Blueprint $table): void {
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::table('blacklisted_emails', function (Blueprint $table): void {
            $table->dropIndex(['email']);
        });
    }
};
