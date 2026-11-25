<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_mails', function (Blueprint $table) {
            $table->index(['client_id', 'sent_at', 'bounced_at', 'complained_at'], 'contact_mails_client_sent_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_mails', function (Blueprint $table) {
            $table->dropIndex('contact_mails_client_sent_at_index');
        });
    }
};
