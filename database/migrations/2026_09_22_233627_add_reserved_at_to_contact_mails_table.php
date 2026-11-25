<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sequence cron sets reserved_at when it claims a due mail, so two
     * ticks cannot dispatch the same mail.
     */
    public function up(): void
    {
        Schema::table('contact_mails', function (Blueprint $table): void {
            $table->timestamp('reserved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_mails', function (Blueprint $table): void {
            $table->dropColumn('reserved_at');
        });
    }
};
