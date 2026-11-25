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
            $table->string('open_ip_address', 45)->nullable()->after('opened_at');
            $table->text('open_user_agent')->nullable()->after('open_ip_address');
            $table->string('open_timezone')->nullable()->after('open_user_agent');
            $table->string('open_country')->nullable()->after('open_timezone');
            $table->string('open_city')->nullable()->after('open_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_mails', function (Blueprint $table) {
            $table->dropColumn(['open_ip_address', 'open_user_agent', 'open_timezone', 'open_country', 'open_city']);
        });
    }
};
