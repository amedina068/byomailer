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
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->string('custom_tracking_domain')->nullable()->after('click_tracking_enabled');
            $table->timestamp('custom_tracking_domain_verified_at')->nullable()->after('custom_tracking_domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->dropColumn(['custom_tracking_domain', 'custom_tracking_domain_verified_at']);
        });
    }
};
