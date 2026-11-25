<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the daily sunset sweep, which reads one client at a time and
 * filters on `sunset_at`, `last_engaged_at`, and `created_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->index(['client_id', 'sunset_at', 'last_engaged_at', 'created_at'], 'contacts_client_sunset_index');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex('contacts_client_sunset_index');
        });
    }
};
