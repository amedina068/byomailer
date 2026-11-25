<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_identities', function (Blueprint $table): void {
            $table->index(
                ['custom_tracking_domain', 'custom_tracking_domain_verified_at'],
                'email_identities_tracking_domain_verified_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('email_identities', function (Blueprint $table): void {
            $table->dropIndex('email_identities_tracking_domain_verified_idx');
        });
    }
};
