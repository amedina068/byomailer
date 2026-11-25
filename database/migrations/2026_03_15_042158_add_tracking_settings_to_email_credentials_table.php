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
            $table->boolean('open_tracking_enabled')->default(true)->after('sns_subscription_confirmed_at');
            $table->boolean('click_tracking_enabled')->default(true)->after('open_tracking_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->dropColumn(['open_tracking_enabled', 'click_tracking_enabled']);
        });
    }
};
