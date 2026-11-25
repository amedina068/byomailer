<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supports the client-bound newsletter metrics aggregate
     * (WHERE client_id = ? AND mailable_type = ? GROUP BY mailable_id).
     */
    public function up(): void
    {
        Schema::table('contact_mails', function (Blueprint $table) {
            $table->index(['client_id', 'mailable_type', 'mailable_id'], 'contact_mails_client_mailable_index');
        });
    }

    public function down(): void
    {
        Schema::table('contact_mails', function (Blueprint $table) {
            $table->dropIndex('contact_mails_client_mailable_index');
        });
    }
};
