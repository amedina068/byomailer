<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Fills `contacts.last_engaged_at` from past clicks before the sunset index
 * exists, so the sweep never treats a contact who clicked as never engaged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('contacts:backfill-last-engaged');
    }

    public function down(): void {}
};
