<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->boolean('can_create_template')->default(false)->after('click_tracking_enabled');
            $table->boolean('can_delete_template')->default(false)->after('can_create_template');
            $table->boolean('can_send_bulk_email')->default(false)->after('can_delete_template');
            $table->boolean('can_send_email')->default(false)->after('can_send_bulk_email');
            $table->timestamp('permissions_checked_at')->nullable()->after('can_send_email');
        });
    }

    public function down(): void
    {
        Schema::table('email_credentials', function (Blueprint $table) {
            $table->dropColumn([
                'can_create_template',
                'can_delete_template',
                'can_send_bulk_email',
                'can_send_email',
                'permissions_checked_at',
            ]);
        });
    }
};
