<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('contact_list')
            ->whereNull('opted_in_at')
            ->update(['opted_in_at' => now()]);
    }

    public function down(): void
    {
        DB::table('contact_list')
            ->update(['opted_in_at' => null]);
    }
};
