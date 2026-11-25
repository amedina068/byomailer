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
        Schema::table('email_identities', function (Blueprint $table) {
            $table->string('reply_to_email')->nullable();
            $table->string('reply_to_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_identities', function (Blueprint $table) {
            $table->dropColumn(['reply_to_email', 'reply_to_name']);
        });
    }
};
