<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Counts soft bounces in a row for a contact. A delivery resets it to 0.
 * The SES event job suppresses the contact at the soft-bounce limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('soft_bounce_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('soft_bounce_count');
        });
    }
};
