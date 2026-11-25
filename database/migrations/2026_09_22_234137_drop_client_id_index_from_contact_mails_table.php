<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the lone client_id index. The (client_id, sent_at) and
     * (client_id, mailable_type, mailable_id) indexes both lead with client_id.
     */
    public function up(): void
    {
        Schema::table('contact_mails', function (Blueprint $table) {
            $table->dropIndex('contact_mails_client_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_mails', function (Blueprint $table) {
            $table->index('client_id', 'contact_mails_client_id_index');
        });
    }
};
