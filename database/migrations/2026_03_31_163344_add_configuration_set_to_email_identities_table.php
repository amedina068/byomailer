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
            $table->string('configuration_set_name', 100)->nullable()->after('region');
            $table->dateTime('configuration_set_created_at')->nullable()->after('configuration_set_name');
            $table->boolean('open_tracking_enabled')->default(true)->after('configuration_set_created_at');
            $table->boolean('click_tracking_enabled')->default(true)->after('open_tracking_enabled');
            $table->string('custom_tracking_domain')->nullable()->after('click_tracking_enabled');
            $table->dateTime('custom_tracking_domain_verified_at')->nullable()->after('custom_tracking_domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_identities', function (Blueprint $table) {
            $table->dropColumn([
                'configuration_set_name',
                'configuration_set_created_at',
                'open_tracking_enabled',
                'click_tracking_enabled',
                'custom_tracking_domain',
                'custom_tracking_domain_verified_at',
            ]);
        });
    }
};
