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
            $table->boolean('production_access_enabled')->default(false)->after('configuration_set_name');
            $table->boolean('sending_enabled')->default(true)->after('production_access_enabled');
            $table->unsignedBigInteger('max_24_hour_send')->default(200)->after('sending_enabled');
            $table->unsignedInteger('max_send_rate')->default(1)->after('max_24_hour_send');
            $table->unsignedBigInteger('sent_last_24_hours')->default(0)->after('max_send_rate');
            $table->timestamp('quota_fetched_at')->nullable()->after('sent_last_24_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->dropColumn([
                'production_access_enabled',
                'sending_enabled',
                'max_24_hour_send',
                'max_send_rate',
                'sent_last_24_hours',
                'quota_fetched_at',
            ]);
        });
    }
};
