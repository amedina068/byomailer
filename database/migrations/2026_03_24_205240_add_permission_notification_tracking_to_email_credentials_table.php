<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->unsignedInteger('permissions_alert_count')->default(0)->after('permissions_checked_at');
            $table->timestamp('permissions_alerted_at')->nullable()->after('permissions_alert_count');
        });
    }

    public function down(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->dropColumn(['permissions_alert_count', 'permissions_alerted_at']);
        });
    }
};
